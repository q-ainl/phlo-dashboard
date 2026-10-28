-- Fleet wide commit log (modules/commit.phlo). One row per commit, no matter how many nodes report it.
--
-- The unique key on hash is the whole idempotence story: the sync inserts with IGNORE, so an overlapping
-- window, a repeated full sweep and six nodes carrying the same engine history all collapse onto one row.
-- The surrogate id stays because the ORM assumes one, and because the insert can then answer with it and
-- say whether the row was new.
--
-- node is where we first saw the commit, not where it was written: after deduplication a commit belongs
-- to a repository, and the machine that happened to report it first is only bookkeeping.
-- committed is the committer date, the same clock git log --since filters on.
-- seen is when this table first learned about it, which is not the same thing and is worth keeping.
CREATE TABLE `commits` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `hash` char(40) NOT NULL,
  `repo` varchar(160) NOT NULL,
  `node` varchar(32) NOT NULL,
  `path` varchar(250) NOT NULL,
  `author_name` varchar(80) NOT NULL,
  `author_email` varchar(120) NOT NULL,
  `subject` varchar(250) NOT NULL,
  `files` smallint(5) unsigned NOT NULL DEFAULT 0,
  `insertions` int(10) unsigned NOT NULL DEFAULT 0,
  `deletions` int(10) unsigned NOT NULL DEFAULT 0,
  `committed` int(10) unsigned NOT NULL,
  `seen` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hash` (`hash`),
  KEY `repo` (`repo`),
  KEY `node` (`node`),
  KEY `author_email` (`author_email`),
  KEY `committed` (`committed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
