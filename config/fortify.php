<?php

use Laravel\Fortify\Features;

return ['guard' => 'web', 'username' => 'email', 'views' => false, 'features' => [Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true])]];
