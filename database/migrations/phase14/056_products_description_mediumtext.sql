-- Supplier XML descriptions can exceed TEXT's 65,535-byte storage limit.
-- Preserve the full source; each marketplace enforces its own publishing limit.
ALTER TABLE products MODIFY COLUMN description MEDIUMTEXT NULL;
