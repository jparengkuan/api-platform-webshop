<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260927182101 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product catalog and customer orders.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE customer_order (id UUID NOT NULL, status VARCHAR(255) NOT NULL, placed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, total_amount INT NOT NULL, total_currency VARCHAR(3) NOT NULL, customer_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3B1CE6A39395C3F39BB7460D ON customer_order (customer_id, placed_at)');
        $this->addSql('CREATE INDEX IDX_3B1CE6A39395C3F3 ON customer_order (customer_id)');
        $this->addSql('CREATE TABLE order_line (id UUID NOT NULL, sku VARCHAR(64) NOT NULL, product_name VARCHAR(255) NOT NULL, quantity INT NOT NULL, unit_price_amount INT NOT NULL, unit_price_currency VARCHAR(3) NOT NULL, order_id UUID NOT NULL, product_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9CE58EE18D9F6D38 ON order_line (order_id)');
        $this->addSql('CREATE INDEX IDX_9CE58EE14584665A ON order_line (product_id)');
        $this->addSql('CREATE TABLE product (id UUID NOT NULL, sku VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, stock INT NOT NULL, active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, price_amount INT NOT NULL, price_currency VARCHAR(3) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D34A04ADF9038C4 ON product (sku)');
        $this->addSql('ALTER TABLE customer_order ADD CONSTRAINT FK_3B1CE6A39395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE order_line ADD CONSTRAINT FK_9CE58EE18D9F6D38 FOREIGN KEY (order_id) REFERENCES customer_order (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE order_line ADD CONSTRAINT FK_9CE58EE14584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE customer_order DROP CONSTRAINT FK_3B1CE6A39395C3F3');
        $this->addSql('ALTER TABLE order_line DROP CONSTRAINT FK_9CE58EE18D9F6D38');
        $this->addSql('ALTER TABLE order_line DROP CONSTRAINT FK_9CE58EE14584665A');
        $this->addSql('DROP TABLE customer_order');
        $this->addSql('DROP TABLE order_line');
        $this->addSql('DROP TABLE product');
    }
}
