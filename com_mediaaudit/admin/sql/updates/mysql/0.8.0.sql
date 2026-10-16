--
-- What a rewrite did: "localize" put a copy in the place of an address, "remove_lost" a note in the
-- place of an image that is lost.
--
ALTER TABLE `#__mediaaudit_rewrites` ADD COLUMN `kind` varchar(20) NOT NULL DEFAULT 'localize' AFTER `field`;

--
-- Rewrites of before: the last change of an article that has the note now, and had none before.
--
UPDATE `#__mediaaudit_rewrites` AS w
INNER JOIN (SELECT MAX(`id`) AS `id` FROM `#__mediaaudit_rewrites` WHERE `reverted_at` IS NULL GROUP BY `source_type`, `source_id`, `field`) AS l ON l.`id` = w.`id`
INNER JOIN `#__content` AS c ON c.`id` = w.`source_id`
SET w.`kind` = 'remove_lost'
WHERE w.`source_type` = 'article'
  AND w.`original` NOT LIKE '%mediaaudit-missing-image%'
  AND ((w.`field` = 'introtext' AND c.`introtext` LIKE '%mediaaudit-missing-image%') OR (w.`field` = 'fulltext' AND c.`fulltext` LIKE '%mediaaudit-missing-image%'));
