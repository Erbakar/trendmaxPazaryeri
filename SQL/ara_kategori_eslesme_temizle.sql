DELETE cmm
FROM category_marketplace_mapping cmm
JOIN marketplace_categories mc
  ON mc.marketplace_id = cmm.marketplace_id
 AND mc.mp_category_id = cmm.marketplace_category_id
WHERE mc.is_leaf = 0;
