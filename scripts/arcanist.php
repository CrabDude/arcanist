#!/usr/bin/env php
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

require_once dirname(__FILE__).'/__init_script__.php';

phutil_require_module('phutil', 'conduit/client');
phutil_require_module('phutil', 'console');
phutil_require_module('phutil', 'future/exec');
phutil_require_module('phutil', 'filesystem');
phutil_require_module('phutil', 'symbols');

phutil_require_module('arcanist', 'exception/usage');
phutil_require_module('arcanist', 'configuration');
phutil_require_module('arcanist', 'workingcopyidentity');
phutil_require_module('arcanist', 'repository/api/base');

$config_trace_mode = false;
$args = array_slice($argv, 1);
foreach ($args as $key => $arg) {
  if ($arg == '--') {
    break;
  } else if ($arg == '--trace') {
    unset($args[$key]);
    $config_trace_mode = true;
  }
}

$args = array_values($args);

try {

  if ($config_trace_mode) {
    ExecFuture::pushEchoMode(true);
  }

  if (!$args) {
    throw new ArcanistUsageException("No command provided. Try 'arc help'.");
  }

  $working_copy = ArcanistWorkingCopyIdentity::newFromPath($_SERVER['PWD']);
  $libs = $working_copy->getConfig('phutil_libraries');
  if ($libs) {
    foreach ($libs as $name => $location) {
      if ($config_trace_mode) {
        echo "Loading phutil library '{$name}' from '{$location}'...\n";
      }
      $library_root = Filesystem::resolvePath(
        $location,
        $working_copy->getProjectRoot());
      phutil_load_library($library_root);
    }
  }

  $config = $working_copy->getConfig('arcanist_configuration');
  if ($config) {
    PhutilSymbolLoader::loadClass($config);
    $config = new $config();
  } else {
    $config = new ArcanistConfiguration();
  }

  $command = strtolower($args[0]);
  $workflow = $config->buildWorkflow($command);
  if (!$workflow) {
    throw new ArcanistUsageException(
      "Unknown command '{$command}'. Try 'arc help'.");
  }
  $workflow->setArcanistConfiguration($config);
  $workflow->setCommand($command);
  $workflow->parseArguments(array_slice($args, 1));

  $need_working_copy    = $workflow->requiresWorkingCopy();
  $need_conduit         = $workflow->requiresConduit();
  $need_auth            = $workflow->requiresAuthentication();
  $need_repository_api  = $workflow->requiresRepositoryAPI();

  $need_conduit       = $need_conduit ||
                        $need_auth;
  $need_working_copy  = $need_working_copy ||
                        $need_conduit ||
                        $need_repository_api;

  if ($need_working_copy) {
    $workflow->setWorkingCopy($working_copy);
  }

  if ($need_conduit) {
    $conduit_uri  = $working_copy->getConduitURI();
    if (!$conduit_uri) {
      throw new ArcanistUsageException(
        "No Conduit URI is specified in the .arcconfig file for this project. ".
        "Specify the Conduit URI for the host Differential is running on.");
    }
    $conduit = new ConduitClient($conduit_uri);
    $conduit->setTraceMode($config_trace_mode);
    $workflow->setConduit($conduit);

    $description = implode(' ', $argv);
    $connection = $conduit->callMethodSynchronous(
      'conduit.connect',
      array(
        'client'            => 'arc',
        'clientVersion'     => 2,
        'clientDescription' => php_uname('n').':'.$description,
        'user'              => getenv('USER'),
      ));
    $conduit->setConnectionID($connection['connectionID']);
  }

  if ($need_repository_api) {
    $repository_api = ArcanistRepositoryAPI::newAPIFromWorkingCopyIdentity(
      $working_copy);
    $workflow->setRepositoryAPI($repository_api);
  }

  if ($need_auth) {
    $user_name = getenv('USER');
    $user_find_future = $conduit->callMethod(
      'user.find',
      array(
        'aliases' => array(
          $user_name,
        ),
      ));
    $user_guids = $user_find_future->resolve();
    if (empty($user_guids[$user_name])) {
      throw new ArcanistUsageException(
        "Username '{$user_name}' is not recognized.");
    }

    $user_guid = $user_guids[$user_name];
    $workflow->setUserGUID($user_guid);
    $workflow->setUserName($user_name);
  }

  $config->willRunWorkflow($command, $workflow);
  $workflow->willRunWorkflow();
  $err = $workflow->run();
  if ($err == 0) {
    $config->didRunWorkflow($command, $workflow);
  }
  exit($err);

} catch (ArcanistUsageException $ex) {
  echo phutil_console_format(
    "**Usage Exception:** %s\n",
    $ex->getMessage());
  if ($config_trace_mode) {
    echo "\n";
    throw $ex;
  }

  exit(1);
} catch (Exception $ex) {
  if ($config_trace_mode) {
    throw $ex;
  }

  echo phutil_console_format(
    "\n**Exception:**\n%s\n%s\n",
    $ex->getMessage(),
    "(Run with --trace for a full exception trace.)");

  exit(1);
}
