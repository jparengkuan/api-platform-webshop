<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds product categories; every product must belong to one.
 */
final class Version20260927194452 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product categories and assign existing products to "Overig".';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE category (id UUID NOT NULL, name VARCHAR(100) NOT NULL, slug VARCHAR(100) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_64C19C15E237E06 ON category (name)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_64C19C1989D9B62 ON category (slug)');

        // Existing products need a category before the column can become NOT NULL. The fallback
        // category is only created when there are products, so empty databases stay clean.
        $this->addSql('ALTER TABLE product ADD category_id UUID DEFAULT NULL');
        $this->addSql("INSERT INTO category (id, name, slug) SELECT gen_random_uuid(), 'Overig', 'overig' WHERE EXISTS (SELECT 1 FROM product)");
        $this->addSql("UPDATE product SET category_id = (SELECT id FROM category WHERE slug = 'overig')");
        $this->addSql('ALTER TABLE product ALTER category_id SET NOT NULL');

        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04AD12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_D34A04AD12469DE2 ON product (category_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP CONSTRAINT FK_D34A04AD12469DE2');
        $this->addSql('DROP INDEX IDX_D34A04AD12469DE2');
        $this->addSql('ALTER TABLE product DROP category_id');
        $this->addSql('DROP TABLE category');
    }
}
