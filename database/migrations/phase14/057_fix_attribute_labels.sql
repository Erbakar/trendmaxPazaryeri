-- Correct legacy seed labels without changing custom names or identifiers.
UPDATE product_attributes SET name='Yaş Grubu' WHERE slug='ty-346' AND name='Ya? Grubu';
UPDATE product_attributes SET name='Menşei' WHERE slug='ty-1192' AND name='Men?ei';
