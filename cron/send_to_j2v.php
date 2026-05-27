<?php
require __DIR__ . '/../vendor/autoload.php';

use JSON2Video\Movie;
use JSON2Video\Scene;

// Create and initialize the movie object
$movie = new Movie;
$movie->setAPIKey('HfMvBu1lEY9MVWfVObFQqFLd48WbCHFKcyvCZhme');
$movie->id = 'qdqrh4hv';
$movie->width = 640;
$movie->height = 360;
$movie->quality = 'high';
$movie->draft = false;

// Create the scenes of the movie

// Create SCENE 1
$scene1 = new Scene;
$scene1->id = 'q55kx8ib';
$scene1->background_color = '#4392F1';
$scene1->addElement([
  'id' => 'qbjh272w',
  'type' => 'text',
  'style' => '008',
  'text' => 'Hello world',
  'settings' => [
    'color' => 'white',
    'font-size' => '10vw',
    'font-family' => 'Bebas Neue'
  ],
  'duration' => 5,
  'cache' => false
]);
$movie->addScene($scene1);


// Each run calls render() once → one new project on json2video.com (waitToFinish only polls; it does not create projects).
$render = $movie->render();
echo 'Created JSON2Video project: ' . ($render['project'] ?? 'unknown') . PHP_EOL;

// Poll until done (default: every 5s). Same status may print more than once while the API is still processing.
$lastStatusKey = null;
$result = $movie->waitToFinish(5, function (array $status, array $quota) use ($movie, &$lastStatusKey): void {
    $key = ($status['status'] ?? '') . '|' . ($status['message'] ?? '');
    if ($key === $lastStatusKey) {
        return;
    }
    $lastStatusKey = $key;
    $movie->printStatus($status, $quota);
});