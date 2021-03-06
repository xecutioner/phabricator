<?php

/*
 * Copyright 2011 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Utility class which encapsulates some shared behavior between different
 * applications which render diffs.
 *
 * @task config Configuring the Engine
 * @task diff Generating Diffs
 */
final class PhabricatorDifferenceEngine {


  private $ignoreWhitespace;
  private $oldName;
  private $newName;


/* -(  Configuring the Engine  )--------------------------------------------- */


  /**
   * If true, ignore whitespace when computing differences.
   *
   * @param bool Ignore whitespace?
   * @return this
   * @task config
   */
  public function setIgnoreWhitespace($ignore_whitespace) {
    $this->ignoreWhitespace = $ignore_whitespace;
    return $this;
  }


  /**
   * Set the name to identify the old file with. Primarily cosmetic.
   *
   * @param  string Old file name.
   * @return this
   * @task config
   */
  public function setOldName($old_name) {
    $this->oldName = $old_name;
    return $this;
  }


  /**
   * Set the name to identify the new file with. Primarily cosmetic.
   *
   * @param  string New file name.
   * @return this
   * @task config
   */
  public function setNewName($new_name) {
    $this->newName = $new_name;
    return $this;
  }


/* -(  Generating Diffs  )--------------------------------------------------- */


  /**
   * Generate a raw diff from two raw files. This is a lower-level API than
   * @{method:generateChangesetFromFileContent}, but may be useful if you need
   * to use a custom parser configuration, as with Diffusion.
   *
   * @param string Entire previous file content.
   * @param string Entire current file content.
   * @return string Raw diff between the two files.
   * @task diff
   */
  public function generateRawDiffFromFileContent($old, $new) {

    $options = array();
    if ($this->ignoreWhitespace) {
      $options[] = '-bw';
    }

    // Generate diffs with full context.
    $options[] = '-U65535';

    $old_name = nonempty($this->oldName, '/dev/universe').' 9999-99-99';
    $new_name = nonempty($this->newName, '/dev/universe').' 9999-99-99';

    $options[] = '-L';
    $options[] = $old_name;
    $options[] = '-L';
    $options[] = $new_name;

    $old_tmp = new TempFile();
    $new_tmp = new TempFile();

    Filesystem::writeFile($old_tmp, $old);
    Filesystem::writeFile($new_tmp, $new);
    list($err, $diff) = exec_manual(
      '/usr/bin/diff %Ls %s %s',
      $options,
      $old_tmp,
      $new_tmp);

    if (!$err) {
      // This indicates that the two files are the same (or, possibly, the
      // same modulo whitespace differences, which is why we can't do this
      // check trivially before running `diff`). Build a synthetic, changeless
      // diff so that we can still render the raw, unchanged file instead of
      // being forced to just say "this file didn't change" since we don't have
      // the content.

      $entire_file = explode("\n", $old);
      foreach ($entire_file as $k => $line) {
        $entire_file[$k] = ' '.$line;
      }
      $len = count($entire_file);
      $entire_file = implode("\n", $entire_file);

      // This is a bit hacky but the diff parser can handle it.
      $diff = "--- {$old_name}\n".
              "+++ {$new_name}\n".
              "@@ -1,{$len} +1,{$len} @@\n".
              $entire_file."\n";
    }

    return $diff;
  }


  /**
   * Generate an @{class:DifferentialChangeset} from two raw files. This is
   * principally useful because you can feed the output to
   * @{class:DifferentialChangesetParser} in order to render it.
   *
   * @param string Entire previous file content.
   * @param string Entire current file content.
   * @return @{class:DifferentialChangeset} Synthetic changeset.
   * @task diff
   */
  public function generateChangesetFromFileContent($old, $new) {
    $diff = $this->generateRawDiffFromFileContent($old, $new);

    $changes = id(new ArcanistDiffParser())->parseDiff($diff);
    $diff = DifferentialDiff::newFromRawChanges($changes);
    return head($diff->getChangesets());
  }

}
