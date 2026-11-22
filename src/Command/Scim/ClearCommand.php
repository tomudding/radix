<?php

declare(strict_types=1);

namespace App\Command\Scim;

use App\Service\Scim\ScimClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function preg_match;
use function sprintf;
use function str_starts_with;

/**
 * Deletes the users and groups the sync manages from the SCIM source, so a re-seed starts from
 * an empty source. `make seed` calls it; only the objects the sync's own scheme names are
 * touched — the accounts are the m-number users, the groups the ones with a radix external id —
 * so the source's own admin and service account survive.
 *
 * Deletion is the hard delete the pinned source performs. The local link table is dropped with
 * the schemas by `make seed` itself; a standalone run's stale links are repaired by the
 * reconciliation, which drops a link whose remote object is gone.
 */
#[AsCommand(
    name: 'app:scim:clear',
    description: 'Delete the users and groups the SCIM sync manages from the source (development).',
)]
final class ClearCommand extends Command
{
    /**
     * The external id prefixes every group the sync creates. A group outside them is not ours,
     * however its name reads.
     */
    private const array GROUP_EXTERNAL_ID_PREFIXES = [
        'BODY:',
        'board:',
        'STATUS:',
        'MEMBER:',
    ];

    public function __construct(
        private readonly ScimClientInterface $client,
        private readonly bool $scimEnabled,
        #[Autowire(param: 'kernel.environment')]
        private readonly string $kernelEnvironment,
    ) {
        parent::__construct();
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        if (!$this->scimEnabled) {
            $output->writeln('SCIM is disabled; nothing to clear.');

            return Command::SUCCESS;
        }

        if ('prod' === $this->kernelEnvironment) {
            $output->writeln('<error>The SCIM source is not cleared under APP_ENV=prod.</error>');

            return Command::FAILURE;
        }

        // The lists are collected before anything is deleted: the source's list walk pages by
        // offset, and deleting during the walk shifts the pages it serves, which breaks the walk.
        $userIds = [];
        foreach ($this->client->listUsers() as $user) {
            if (
                null === $user->remoteId
                || 1 !== preg_match(
                    '/^m(\d+)$/',
                    (string) $user->userName,
                )
            ) {
                continue;
            }

            $userIds[] = $user->remoteId;
        }

        $users = 0;
        foreach ($userIds as $remoteId) {
            $this->client->deleteUser($remoteId);
            ++$users;
        }

        $groupIds = [];
        foreach ($this->client->listGroups() as $group) {
            if (
                null === $group->remoteId
                || !$this->isOurs((string) $group->externalId)
            ) {
                continue;
            }

            $groupIds[] = $group->remoteId;
        }

        $groups = 0;
        foreach ($groupIds as $remoteId) {
            $this->client->deleteGroup($remoteId);
            ++$groups;
        }

        $output->writeln(sprintf(
            'Deleted %d users and %d groups from the SCIM source.',
            $users,
            $groups,
        ));

        return Command::SUCCESS;
    }

    private function isOurs(string $externalId): bool
    {
        foreach (self::GROUP_EXTERNAL_ID_PREFIXES as $prefix) {
            if (
                str_starts_with(
                    $externalId,
                    $prefix,
                )
            ) {
                return true;
            }
        }

        return false;
    }
}
