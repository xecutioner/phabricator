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

class ManiphestTransactionEditor {

  public function applyTransactions($task, array $transactions) {

    $email_cc = $task->getCCPHIDs();

    $email_to = array();
    $email_to[] = $task->getOwnerPHID();

    foreach ($transactions as $transaction) {
      $type = $transaction->getTransactionType();
      $new = $transaction->getNewValue();
      $email_to[] = $transaction->getAuthorPHID();

      switch ($type) {
        case ManiphestTransactionType::TYPE_NONE:
          $old = null;
          break;
        case ManiphestTransactionType::TYPE_STATUS:
          $old = $task->getStatus();
          break;
        case ManiphestTransactionType::TYPE_OWNER:
          $old = $task->getOwnerPHID();
          break;
        case ManiphestTransactionType::TYPE_CCS:
          $old = $task->getCCPHIDs();
          break;
        case ManiphestTransactionType::TYPE_PRIORITY:
          $old = $task->getPriority();
          break;
        default:
          throw new Exception('Unknown action type.');
      }

      if (($old !== null) && ($old == $new)) {
        $transaction->setOldValue(null);
        $transaction->setNewValue(null);
        $transaction->setTransactionType(ManiphestTransactionType::TYPE_NONE);
      } else {
        switch ($type) {
          case ManiphestTransactionType::TYPE_NONE:
            break;
          case ManiphestTransactionType::TYPE_STATUS:
            $task->setStatus($new);
            break;
          case ManiphestTransactionType::TYPE_OWNER:
            $task->setOwnerPHID($new);
            break;
          case ManiphestTransactionType::TYPE_CCS:
            $task->setCCPHIDs($new);
            break;
          case ManiphestTransactionType::TYPE_PRIORITY:
            $task->setPriority($new);
            break;
          default:
            throw new Exception('Unknown action type.');
        }

        $transaction->setOldValue($old);
        $transaction->setNewValue($new);
      }

    }

    $task->save();
    foreach ($transactions as $transaction) {
      $transaction->setTaskID($task->getID());
      $transaction->save();
    }

    $email_to[] = $task->getOwnerPHID();
    $email_cc = array_merge(
      $email_cc,
      $task->getCCPHIDs());

    $this->sendEmail($task, $transactions, $email_to, $email_cc);
  }

  private function sendEmail($task, $transactions, $email_to, $email_cc) {
    $email_to = array_filter(array_unique($email_to));
    $email_cc = array_filter(array_unique($email_cc));

    $phids = array();
    foreach ($transactions as $transaction) {
      foreach ($transaction->extractPHIDs() as $phid) {
        $phids[$phid] = true;
      }
    }
    $phids = array_keys($phids);

    $handles = id(new PhabricatorObjectHandleData($phids))
      ->loadHandles();


    $view = new ManiphestTransactionDetailView();
    $view->setTransactionGroup($transactions);
    $view->setHandles($handles);
    list($action, $body) = $view->renderForEmail($with_date = false);

    $is_create = false;
    foreach ($transactions as $transaction) {
      $type = $transaction->getTransactionType();
      if (($type == ManiphestTransactionType::TYPE_STATUS) &&
          ($transaction->getOldValue() === null) &&
          ($transaction->getNewValue() == ManiphestTaskStatus::STATUS_OPEN)) {
        $is_create = true;
      }
    }

    $task_uri = PhabricatorEnv::getURI('/T'.$task->getID());

    if ($is_create) {
      $body .=
        "\n\n".
        "TASK DESCRIPTION\n".
        "  ".$task->getDescription();
    }

    $body .=
      "\n\n".
      "TASK DETAIL\n".
      "  ".$task_uri."\n";

    id(new PhabricatorMetaMTAMail())
      ->setSubject(
        '[Maniphest] '.$action.': T'.$task->getID().' '.$task->getTitle())
      ->setFrom($transaction->getAuthorPHID())
      ->addTos($email_to)
      ->addCCs($email_cc)
      ->setBody($body)
      ->save();
  }
}