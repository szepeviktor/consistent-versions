<?php

$configuration = require dirname(__DIR__) . '/example-config.php';

$ghaWorkflow = yaml_parse_file($configuration['github_actions_workflow']);
var_dump($ghaWorkflow['jobs']['run-all']['steps'][0]['with']['php-version']);
