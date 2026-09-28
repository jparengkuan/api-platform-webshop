<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260928005110 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the Idempotency-Key of the request that placed an order, unique per customer.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_order ADD idempotency_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3B1CE6A39395C3F37FD1C147 ON customer_order (customer_id, idempotency_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_3B1CE6A39395C3F37FD1C147');
        $this->addSql('ALTER TABLE customer_order DROP idempotency_key');
    }
}
