<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// the memory package speaks the agent's language in the tests, as in bootstrap/index.php
\Mercurius\Memory\Texts::translateWith(static fn(string $key): string => \Mercurius\I18n\t($key));
\Mercurius\Peer\Texts::translateWith(static fn(string $key): string => \Mercurius\I18n\t($key));
