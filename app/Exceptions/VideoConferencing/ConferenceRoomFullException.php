<?php

namespace App\Exceptions\VideoConferencing;

use RuntimeException;

/** Every individual member place in the lineup is taken; no participation was created. */
class ConferenceRoomFullException extends RuntimeException {}
