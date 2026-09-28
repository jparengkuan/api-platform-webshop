<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260928001350 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add payment to orders (paid_at, payment_reference); orders from before payments existed count as paid.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_order ADD paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE customer_order ADD payment_reference VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_3B1CE6A37B00651C9BB7460D ON customer_order (status, placed_at)');

        // Orders from before payments existed were accepted as they were: mark them paid, otherwise
        // the task that expires unpaid orders would cancel them and put their stock back.
        $this->addSql("UPDATE customer_order SET status = 'paid', paid_at = placed_at WHERE status = 'placed'");
    }

    public function down(Schema $schema): void
    {
        // The previous version only knows "placed".
        $this->addSql("UPDATE customer_order SET status = 'placed' WHERE status IN ('paid', 'expired')");
        $this->addSql('DROP INDEX IDX_3B1CE6A37B00651C9BB7460D');
        $this->addSql('ALTER TABLE customer_order DROP paid_at');
        $this->addSql('ALTER TABLE customer_order DROP payment_reference');
    }
}
