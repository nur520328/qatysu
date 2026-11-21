<?php

namespace App\Enums;

/** Кезекшінің 1-сабақтағы белгісі. */
enum RoundStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
}
