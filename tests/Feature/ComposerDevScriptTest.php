<?php

it('seeds the default user and engagement inbox before starting development services', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $dev = $composer['scripts']['dev'];

    expect($dev)->toContain('@php artisan db:seed --class=DefaultUserSeeder --force --no-interaction')
        ->and($dev)->toContain('@php artisan db:seed --class=DummyEngagementSeeder --force --no-interaction')
        ->and(array_search('@php artisan db:seed --class=DefaultUserSeeder --force --no-interaction', $dev, true))
        ->toBeLessThan(array_search('@php artisan db:seed --class=DummyEngagementSeeder --force --no-interaction', $dev, true));
});
