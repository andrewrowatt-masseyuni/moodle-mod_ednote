<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Version information for the teacher note.
 *
 * @package    mod_ednote
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'mod_ednote';
$plugin->release = '0.1.0';
$plugin->version = 2026080200;
$plugin->requires = 2024100700;
// Pinned to 4.5 to match mod_edpreset, which is this plugin's main producer. Nothing here depends
// on a deprecated API, but the two are developed and tested as a pair.
$plugin->supported = [405, 405];
$plugin->maturity = MATURITY_ALPHA;

// Deliberately NO $plugin->dependencies on mod_edpreset. The link is one-way and soft: a note
// carrying a presetid renders that preset's guidance if mod_edpreset is installed, and falls back
// to its own stored copy if it is not. Either plugin installs and works on its own.
