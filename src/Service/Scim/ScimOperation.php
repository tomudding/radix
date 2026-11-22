<?php

declare(strict_types=1);

namespace App\Service\Scim;

use ValueError;

use function in_array;
use function sprintf;

/**
 * A single SCIM PATCH operation.
 */
final readonly class ScimOperation
{
    private const array OPERATIONS = [
        'add',
        'remove',
        'replace',
    ];

    public function __construct(
        public string $op,
        public mixed $value,
        public ?string $path = null,
    ) {
        if (
            !in_array(
                $this->op,
                self::OPERATIONS,
                true,
            )
        ) {
            throw new ValueError(sprintf('Unknown SCIM operation "%s".', $this->op));
        }
    }

    public static function add(
        ?string $path,
        mixed $value,
    ): self {
        return new self(
            'add',
            $value,
            $path,
        );
    }

    public static function remove(
        ?string $path,
        mixed $value,
    ): self {
        return new self(
            'remove',
            $value,
            $path,
        );
    }

    public static function replace(
        ?string $path,
        mixed $value,
    ): self {
        return new self(
            'replace',
            $value,
            $path,
        );
    }

    /**
     * @return array{op: string, path?: string, value: mixed}
     */
    public function toArray(): array
    {
        $operation = ['op' => $this->op];
        if (null !== $this->path) {
            $operation['path'] = $this->path;
        }

        $operation['value'] = $this->value;

        return $operation;
    }
}
