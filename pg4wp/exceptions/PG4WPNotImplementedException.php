<?php

/**
 * Thrown by the mysqli compatibility shims in the pg4wp drivers when WordPress
 * calls a function that has no PostgreSQL equivalent yet.
 */
class PG4WPNotImplementedException extends Exception
{
}
