<?hh // strict
/**
 * Copyright (c) Facebook, Inc. and its affiliates.
 *
 * This source code is licensed under the MIT license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * This file was moved from fbsource to www. View old history in diffusion:
 * https://fburl.com/mxrtk0pl
 */
namespace Facebook\ShipIt;

use namespace HH\Lib\{Str, C};

class ShipItRepoException extends \Exception {
  public function __construct(?ShipItRepo $repo, string $message) {
    if ($repo !== null) {
      $message = \get_class($repo).": $message";
    }
    parent::__construct($message);
  }
}

/**
 * Repo handler interface
 * For agnostic communication with git, hg, etc...
 */
abstract class ShipItRepo {
  private ShipItScopedFlock $lock;

  /**
   * @param $path the path to the repository
   */
  public function __construct(protected string $path, string $branch) {
    $this->lock = self::createSharedLockForPath($path);
    $this->setBranch($branch);
  }

  /**
   * Get the ShipItChangeset of the HEAD revision in the current branch.
   */
  public abstract function getHeadChangeset(): ?ShipItChangeset;

  protected function getSharedLock(): ShipItScopedFlock {
    return $this->lock;
  }

  const VERBOSE_FETCH = 1;
  const VERBOSE_SHELL = 2;
  const VERBOSE_SHELL_OUTPUT = 4;
  const VERBOSE_SHELL_INPUT = 8;

  // Level of verbosity for -v option
  const VERBOSE_STANDARD = 3;

  static public int $verbose = 0;

  const TYPE_GIT = 'git';
  const TYPE_HG = 'hg';

  public function getPath(): string {
    return $this->path;
  }

  public static function createSharedLockForPath(
    string $repo_path,
  ): ShipItScopedFlock {
    return ShipItScopedFlock::createShared(
      self::getLockFilePathForRepoPath($repo_path),
    );
  }

  public static function getLockFilePathForRepoPath(string $repo_path): string {
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    return \dirname($repo_path).'/'.\basename($repo_path).'.fbshipit-lock';
  }

  /**
   * Implement to allow changing branches
   */
  protected abstract function setBranch(string $branch): bool;

  public abstract function updateBranchTo(string $base_rev): void;

  /**
   * Cleans our checkout.
   */
  public abstract function clean(): void;

  /**
   * Updates our checkout
   */
  public abstract function pull(): void;

  /**
   * push lfs support
   */
  public abstract function pushLfs(
    string $lfs_pull_endpoint,
    string $lfs_push_endpoint,
  ): void;

  /**
   * Get the origin of the checkout.
   */
  public abstract function getOrigin(): string;

  public static function typedOpen<Trepo as ShipItRepo>(
    classname<Trepo> $interface,
    string $path,
    string $branch,
  ): Trepo {
    $repo = ShipItRepo::open($path, $branch);
    invariant(
      \is_a($repo, $interface),
      '%s is a %s, needed a %s',
      $path,
      \get_class($repo),
      $interface,
    );
    /* HH_FIXME[4110] */
    return $repo;
  }

  /**
   * Factory
   */
  public static function open(string $path, string $branch): ShipItRepo {
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    if (\file_exists($path.'/.git')) {
      return new ShipItRepoGIT($path, $branch);
    }
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    if (\file_exists($path.'/.hg')) {
      return new ShipItRepoHG($path, $branch);
    }
    throw new ShipItRepoException(
      null,
      "Can't determine type of repo at ".$path,
    );
  }

  /**
   * Convert a hunk to a ShipItDiff shape
   */
  protected static function parseDiffHunk(string $hunk): ?ShipItDiff {
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    list($header, $body) = \explode("\n", $hunk, 2);
    $matches = varray[];
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    \preg_match(
      '@^diff --git [ab]/(.*?) [ab]/(.*?)$@',
      Str\trim($header),
      inout $matches,
    );
    if (C\is_empty($matches)) {
      return null;
    }
    return shape(
      'path' => $matches[1],
      'body' => $body,
    );
  }

  public abstract static function getDiffsFromPatch(
    string $patch,
  ): vec<ShipItDiff>;

  final public static function getCommitMessage(
    ShipItChangeset $changeset,
  ): string {
    return $changeset->getSubject()."\n\n".$changeset->getMessage();
  }
}
