<?php

require __DIR__ . '\..\src\CLIUtil.php';

define('TOTAL_ITEMS', 1e4);

$u = new CLIUtil(array(
    'script_name' => 'Test #1',
    'script_version' => '1.0',
    'script_description' => 'Test script #1 for CLIUtil',
    'verbocity_default' => CLIUtil::MESSAGE_STATUS .
      CLIUtil::MESSAGE_INFORMATION .
      CLIUtil::MESSAGE_ERROR .
      CLIUtil::VERB_PROGRESS
));

if ($u->getParameter('?'))
{
  $u->displayHelp();
  exit;
}

//

$u->options->progress_items_total = TOTAL_ITEMS;

$u->start();

for ($i = 1; $i <= TOTAL_ITEMS; $i++)
{
  $u->updateProgress($i);
  usleep(1e6 * rand(0, 15) / 1000);

  if ($i % 100 == 0)
  {
    $u->info("\$i is $i");
  }
}

$u->end();