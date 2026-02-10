<?php
echo 'LOGISTICS:' . PHP_EOL;
foreach(['MiguelAnchia@miamibeachfl.gov','RichardQuintela@miamibeachfl.gov','peterdarley@miamibeachfl.gov','geralddeyoung@miamibeachfl.gov'] as $e) {
    $u = \App\Models\User::where('email', $e)->first();
    echo $e . ': ' . ($u ? implode(',', $u->getRoleNames()->toArray()) : 'NOT FOUND') . PHP_EOL;
}
echo 'TRAINING:' . PHP_EOL;
foreach(['danielgato@miamibeachfl.gov','victorwhite@miamibeachfl.gov','ClaudioNavas@miamibeachfl.gov','michaelsica@miamibeachfl.gov','GreciaTrabanino@miamibeachfl.gov'] as $e) {
    $u = \App\Models\User::where('email', $e)->first();
    echo $e . ': ' . ($u ? implode(',', $u->getRoleNames()->toArray()) : 'NOT FOUND') . PHP_EOL;
}
