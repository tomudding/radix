<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 */
final class Version20261118120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The SCIM remote link maps a local user or organ to the object the SCIM source manages.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ScimRemoteLink (id INT AUTO_INCREMENT NOT NULL, localType VARCHAR(255) NOT NULL, localId INT NOT NULL, remoteId VARCHAR(255) NOT NULL, payloadHash VARCHAR(255) DEFAULT NULL, lastSyncedAt DATETIME DEFAULT NULL, lastError LONGTEXT DEFAULT NULL, UNIQUE INDEX scim_remote_link_local_uniq (localType, localId), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ScimRemoteLink');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
