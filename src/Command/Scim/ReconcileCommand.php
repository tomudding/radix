<?php

declare(strict_types=1);

namespace App\Command\Scim;

use App\Command\HoldsRunLockTrait;
use App\Entity\User\Enums\ScimLocalTypes;
use App\Entity\User\ScimRemoteLink;
use App\Entity\User\User;
use App\Message\Scim\SyncGroupMembershipMessage;
use App\Message\Scim\SyncGroupMessage;
use App\Message\Scim\SyncUserMessage;
use App\Repository\Decision\OrganMemberRepository;
use App\Repository\Decision\OrganRepository;
use App\Repository\User\ScimRemoteLinkRepository;
use App\Repository\User\UserRepository;
use App\Service\Scim\GroupScimMapper;
use App\Service\Scim\GroupScimSource;
use App\Service\Scim\ScimClientInterface;
use App\Service\Scim\ScimGroup;
use App\Service\Scim\ScimGroupKey;
use App\Service\Scim\ScimUser;
use App\Service\Scim\UserScimMapper;
use App\Service\Scim\UserScimSource;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

use function array_column;
use function array_diff;
use function array_keys;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function implode;
use function in_array;
use function preg_match;
use function preg_replace;
use function sort;
use function sprintf;

/**
 * Brings the SCIM source and the local state in line with each other. The ordinary sync covers
 * what a register write touched; this covers the rest (a remote object changed out of band, a
 * lost link, a payload that changed through a path that does not dispatch). The nightly run sits
 * an hour after `app:decision:generate`, whose rebuild rewrites the projection.
 *
 * The comparison is against the lists the source returns, not the link table alone: an unchanged
 * payload hash is still compared field by field, because a change on the source side is drift.
 * Drift is repaired by dispatching the ordinary sync messages, so this run makes only the list
 * requests. Nothing is ever removed from the source: an object that matches no local member or
 * organ is logged as an orphan; a link whose remote object is gone is dropped first, so the
 * dispatched sync recreates the object.
 */
#[AsCommand(
    name: 'app:scim:reconcile',
    description: 'Diff the local users and groups against the SCIM source and dispatch the repair syncs.',
)]
#[AsCronTask(
    expression: '0 2 * * *',
    transports: 'maintenance',
)]
final class ReconcileCommand extends Command
{
    use HoldsRunLockTrait;

    public function __construct(
        private readonly ScimClientInterface $client,
        private readonly UserScimMapper $userMapper,
        private readonly GroupScimMapper $groupMapper,
        private readonly UserRepository $userRepository,
        private readonly OrganRepository $organRepository,
        private readonly OrganMemberRepository $organMemberRepository,
        private readonly ScimRemoteLinkRepository $linkRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly bool $scimEnabled,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Report the drift without dispatching any sync.',
            )
            ->addOption(
                'full',
                null,
                InputOption::VALUE_NONE,
                'Dispatch a sync for every user and group; the per-object hash skip still applies, so '
                . 'stale links need a diff run.',
            )
            ->addOption(
                'type',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Limit the run to a kind of object',
                [
                    'user',
                    'group',
                ],
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        return $this->runExclusively(
            $output,
            fn (): int => $this->executeExclusively(
                $input,
                $output,
            ),
        );
    }

    private function executeExclusively(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        if (!$this->scimEnabled) {
            $output->writeln('SCIM is disabled; nothing to reconcile.');

            return Command::SUCCESS;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $full = (bool) $input->getOption('full');
        $types = $input->getOption('type');
        if (
            !in_array(
                'user',
                $types,
                true,
            )
            && !in_array(
                'group',
                $types,
                true,
            )
        ) {
            $output->writeln('<error>--type may only be "user" and "group".</error>');

            return Command::INVALID;
        }

        $counts = [
            'user' => 0,
            'group' => 0,
            'membership' => 0,
        ];
        $dispatchedLidnrs = [];
        $pending = [];
        $dispatch = static function (
            object $message,
            string $kind,
        ) use (
            $dryRun,
            &$counts,
            &$dispatchedLidnrs,
            &$pending,
        ): void {
            // A member without a link shows up in the user diff and again in the membership of
            // every organ the member is installed in; one user sync repairs all of it.
            if ($message instanceof SyncUserMessage) {
                if (
                    in_array(
                        $message->getLidnr(),
                        $dispatchedLidnrs,
                        true,
                    )
                ) {
                    return;
                }

                $dispatchedLidnrs[] = $message->getLidnr();
            }

            if (!$dryRun) {
                $pending[] = $message;
            }

            ++$counts[$kind];
        };

        if ($full) {
            if (
                in_array(
                    'user',
                    $types,
                    true,
                )
            ) {
                foreach ($this->userRepository->findAll() as $user) {
                    $dispatch(new SyncUserMessage($user->member->lidnr), 'user');
                }
            }

            if (
                in_array(
                    'group',
                    $types,
                    true,
                )
            ) {
                foreach ($this->organRepository->findActive() as $organ) {
                    $dispatch(new SyncGroupMessage((int) $organ->id), 'group');
                    $dispatch(new SyncGroupMembershipMessage((int) $organ->id), 'membership');
                }
            }
        } else {
            $orphans = [
                'user' => [],
                'group' => [],
            ];
            $staleLinks = [];
            if (
                in_array(
                    'user',
                    $types,
                    true,
                )
            ) {
                $orphans['user'] = $this->reconcileUsers(
                    $dispatch,
                    $output,
                    $staleLinks,
                );
            }

            if (
                in_array(
                    'group',
                    $types,
                    true,
                )
            ) {
                $orphans['group'] = $this->reconcileGroups(
                    $dispatch,
                    $output,
                    $staleLinks,
                );
            }

            $this->reportOrphans(
                $output,
                $orphans,
                $counts['user'] + $counts['group'] + $counts['membership'],
            );
            $this->dropStaleLinks(
                $staleLinks,
                $dryRun,
                $output,
            );
        }

        // The stale links are dropped before the repairs are dispatched: the handlers rely on the
        // link table and skip an unchanged hash, so a sync dispatched before the drop would take
        // the skip path and the recreation would wait for the next run.
        foreach ($pending as $message) {
            $this->messageBus->dispatch($message);
        }

        $output->writeln(
            $this->summaryLine(
                $counts['user'],
                $counts['group'],
                $counts['membership'],
                $dryRun,
            ),
        );

        return Command::SUCCESS;
    }

    /**
     * @param callable(object, string): void $dispatch
     * @param list<ScimRemoteLink>           $staleLinks
     *
     * @return list<string> The user names of the remote users that belong to no local member
     */
    private function reconcileUsers(
        callable $dispatch,
        OutputInterface $output,
        array &$staleLinks,
    ): array {
        $remote = [];
        foreach ($this->client->listUsers() as $user) {
            if (null === $user->remoteId) {
                continue;
            }

            $remote[$user->remoteId] = $user;
        }

        $userLinks = $this->linksOf(ScimLocalTypes::User);
        $localUsers = $this->userRepository->findAll();
        $localLidnrs = array_map(
            static fn (User $user): int => $user->member->lidnr,
            $localUsers,
        );

        foreach ($localUsers as $user) {
            $member = $user->member;
            $lidnr = $member->lidnr;

            $scimUser = $this->userMapper->map(
                new UserScimSource(
                    lidnr: (string) $lidnr,
                    firstName: $member->firstName,
                    lastName: $member->lastName,
                    email: $member->email,
                    active: !$member->hidden,
                ),
            );

            $link = $userLinks[$lidnr] ?? null;
            $remoteUser = null !== $link
                ? ($remote[$link->remoteId] ?? null)
                : null;
            $hashUnchanged = null !== $link && $scimUser->payloadHash() === $link->payloadHash;
            $contentDrift = $hashUnchanged
                && null !== $remoteUser
                && !$this->userMatchesSource(
                    $scimUser,
                    $remoteUser,
                );
            if (
                null !== $link
                && $hashUnchanged
                && null !== $remoteUser
                && false === $contentDrift
            ) {
                continue;
            }

            $output->writeln(sprintf(
                '    member %d: %s',
                $lidnr,
                $this->driftDescription(
                    $link,
                    $remote,
                    $contentDrift,
                ),
            ));
            if (
                null !== $link
                && !isset($remote[$link->remoteId])
            ) {
                $staleLinks[] = $link;
            }

            $dispatch(new SyncUserMessage($lidnr), 'user');
        }

        // Only names in our own scheme can be ours; the source also lists the Authentik's native
        // accounts, and the sync must never touch them.
        $orphans = [];
        foreach ($remote as $user) {
            if (
                1 !== preg_match(
                    '/^m(\d+)$/',
                    (string) $user->userName,
                    $matches,
                )
                || in_array(
                    (int) $matches[1],
                    $localLidnrs,
                    true,
                )
            ) {
                continue;
            }

            $orphans[] = (string) $user->userName;
        }

        return $orphans;
    }

    /**
     * @param callable(object, string): void $dispatch
     * @param list<ScimRemoteLink>           $staleLinks
     *
     * @return list<string> The external identifiers of the remote groups that belong to no local organ
     */
    private function reconcileGroups(
        callable $dispatch,
        OutputInterface $output,
        array &$staleLinks,
    ): array {
        $remote = [];
        foreach ($this->client->listGroups() as $group) {
            if (null === $group->remoteId) {
                continue;
            }

            $remote[$group->remoteId] = $group;
        }

        $groupLinks = $this->linksOf(ScimLocalTypes::Group);
        $organs = $this->organRepository->findActive();
        $organKeys = [];
        foreach ($organs as $organ) {
            $organKeys[ScimGroupKey::for($organ)] = $organ;
        }

        foreach ($organs as $organ) {
            $organId = (int) $organ->id;
            $memberLidnrs = $this->organMemberRepository->findCurrentMemberLidnrs($organId);
            $memberRemoteIds = $this->linkRepository->findUserRemoteIds($memberLidnrs);
            $unlinked = array_diff(
                $memberLidnrs,
                array_keys($memberRemoteIds),
            );

            if ([] !== $unlinked) {
                // A member without a link cannot be put in the group: the user sync goes first,
                // and it re-triggers this organ's membership once the link exists.
                foreach ($unlinked as $lidnr) {
                    $dispatch(new SyncUserMessage((int) $lidnr), 'user');
                }

                $output->writeln(sprintf(
                    '    organ %d (%s): %d member%s without a link yet; the membership sync follows their user sync%s.',
                    $organId,
                    $organ->name,
                    count($unlinked),
                    1 === count($unlinked) ? ' is' : 's are',
                    1 === count($unlinked) ? 's' : '',
                ));
                $dispatch(new SyncGroupMembershipMessage($organId), 'membership');

                continue;
            }

            $scimGroup = $this->groupMapper->map(
                new GroupScimSource(
                    displayName: $organ->name,
                    externalId: ScimGroupKey::for($organ),
                    memberRemoteIds: array_values($memberRemoteIds),
                ),
            );

            $link = $groupLinks[$organId] ?? null;
            $remoteGroup = null !== $link
                ? ($remote[$link->remoteId] ?? null)
                : null;
            $hashUnchanged = null !== $link && $scimGroup->payloadHash() === $link->payloadHash;
            $contentDrift = $hashUnchanged
                && null !== $remoteGroup
                && !$this->groupMatchesSource(
                    $scimGroup,
                    $remoteGroup,
                );
            if (
                null !== $link
                && $hashUnchanged
                && null !== $remoteGroup
                && false === $contentDrift
            ) {
                continue;
            }

            $output->writeln(sprintf(
                '    organ %d (%s): %s',
                $organId,
                $organ->name,
                $this->driftDescription(
                    $link,
                    $remote,
                    $contentDrift,
                ),
            ));
            if (
                null !== $link
                && !isset($remote[$link->remoteId])
            ) {
                $staleLinks[] = $link;
            }

            $dispatch(new SyncGroupMessage($organId), 'group');
            $dispatch(new SyncGroupMembershipMessage($organId), 'membership');
        }

        // Groups without an external identifier are the Authentik's own and are never ours;
        // ours are recognised by the key scheme, so an unknown key is an orphan. A linked group
        // is never an orphan: if its external identifier changed on the source, the content
        // comparison above already reports it as drift.
        $linkedRemoteIds = array_column(
            $groupLinks,
            'remoteId',
        );
        $orphans = [];
        foreach ($remote as $group) {
            if (
                null === $group->externalId
                || isset($organKeys[$group->externalId])
                || in_array(
                    $group->remoteId,
                    $linkedRemoteIds,
                    true,
                )
            ) {
                continue;
            }

            $orphans[] = $this->printable((string) $group->externalId);
        }

        return $orphans;
    }

    /**
     * @return array<int, ScimRemoteLink> local identifier to link
     */
    private function linksOf(ScimLocalTypes $type): array
    {
        $links = [];
        foreach ($this->linkRepository->findAll() as $link) {
            if ($link->localType !== $type) {
                continue;
            }

            $links[$link->localId] = $link;
        }

        return $links;
    }

    /**
     * @param list<ScimRemoteLink> $staleLinks
     */
    private function dropStaleLinks(
        array $staleLinks,
        bool $dryRun,
        OutputInterface $output,
    ): void {
        $count = count($staleLinks);
        if (0 === $count) {
            return;
        }

        if ($dryRun) {
            $output->writeln(sprintf(
                'Dry run; would drop %d stale link%s for object%s gone from the source.',
                $count,
                1 === $count ? '' : 's',
                1 === $count ? '' : 's',
            ));

            return;
        }

        $this->linkRepository->drop($staleLinks);
        $this->logger->info(
            'SCIM reconciliation dropped {count} stale link(s); the dispatched sync recreates the remote object.',
            ['count' => $count],
        );
    }

    /**
     * @param array<string, object> $remote
     */
    private function driftDescription(
        ?ScimRemoteLink $link,
        array $remote,
        bool $contentDrift,
    ): string {
        if (null === $link) {
            return 'no link to the source';
        }

        if (!isset($remote[$link->remoteId])) {
            return 'the remote object is gone from the source';
        }

        return $contentDrift
            ? 'the source changed what was last synced'
            : 'the payload differs from what was last synced';
    }

    /**
     * The fields the source must not have changed out of band, compared against the payload a
     * sync would send. The user name is part of the comparison because a rename would also
     * hide the object from the orphan scan, which only recognises the `m<lidnr>` scheme.
     */
    private function userMatchesSource(
        ScimUser $local,
        ScimUser $remote,
    ): bool {
        if (
            $local->userName !== $remote->userName
            || $local->active !== $remote->active
        ) {
            return false;
        }

        $localEmail = $local->emails[0]->value ?? '';
        $remoteEmail = null;
        foreach ($remote->emails as $email) {
            if ($email->primary) {
                $remoteEmail = $email->value;

                break;
            }
        }

        return $localEmail === ($remoteEmail ?? '');
    }

    /**
     * The membership runs on sorted copies: the list order is the source's, and a reorder of
     * the same members is not drift.
     */
    private function groupMatchesSource(
        ScimGroup $local,
        ScimGroup $remote,
    ): bool {
        if (
            $local->displayName !== $remote->displayName
            || $local->externalId !== $remote->externalId
        ) {
            return false;
        }

        $localMembers = $local->memberRemoteIds;
        $remoteMembers = $remote->memberRemoteIds;
        sort($localMembers);
        sort($remoteMembers);

        return $localMembers === $remoteMembers;
    }

    /**
     * A console line must not carry an escape sequence a remote object supplied.
     */
    private function printable(string $value): string
    {
        return preg_replace(
            '/[^\x20-\x7E]/',
            '?',
            $value,
        ) ?? '';
    }

    /**
     * @param array{user: list<string>, group: list<string>} $orphans
     */
    private function reportOrphans(
        OutputInterface $output,
        array $orphans,
        int $dispatched,
    ): void {
        if (
            [] === $orphans['user']
            && [] === $orphans['group']
        ) {
            if (0 === $dispatched) {
                $output->writeln('No drift, no orphans.');
            }

            return;
        }

        $output->writeln(sprintf(
            'Orphans on the source (left untouched; deletion is a policy decision pending): %s, %s',
            [] === $orphans['user'] ? 'no users' : sprintf(
                '%d user%s: %s',
                count($orphans['user']),
                1 === count($orphans['user']) ? '' : 's',
                implode(
                    ', ',
                    array_slice(
                        $orphans['user'],
                        0,
                        20,
                    ),
                ),
            ),
            [] === $orphans['group'] ? 'no groups' : sprintf(
                '%d group%s: %s',
                count($orphans['group']),
                1 === count($orphans['group']) ? '' : 's',
                implode(
                    ', ',
                    array_slice(
                        $orphans['group'],
                        0,
                        20,
                    ),
                ),
            ),
        ));

        $this->logger->warning(
            'SCIM reconciliation found orphans on the source; they are left untouched: {orphans}',
            [
                'orphanUsers' => $orphans['user'],
                'orphanGroups' => $orphans['group'],
            ],
        );
    }

    private function summaryLine(
        int $users,
        int $groups,
        int $memberships,
        bool $dryRun,
    ): string {
        $line = sprintf(
            '%d user%s, %d group%s and %d membership sync%s.',
            $users,
            1 === $users ? '' : 's',
            $groups,
            1 === $groups ? '' : 's',
            $memberships,
            1 === $memberships ? '' : 's',
        );

        return $dryRun
            ? 'Dry run; would dispatch ' . $line
            : 'Dispatched ' . $line;
    }
}
