<?php

/**
 * class ImportException extends the build-in Exception class and
 * catches potential errors
 */
class ImportException extends Exception
{
	public static function error_handler_callback($code, $string, $file, $line)
	{
		$e = new self($string, $code);
		$e->line = $line;
		$e->file = $file;
		throw $e;
	}

	/**
	 * @param Exception $exception
	 */
	public static function exception_handler($exception, $template = null)
	{
		global $import;

		if ($template === null)
		{
			if (!empty($import))
				$template = $import->template;
			else
				$template = new Template();
		}

		$message = $exception->getMessage();
		$trace = $exception->getTrace();
		$line = $exception->getLine();
		$file = $exception->getFile();
		$template->error($message, $trace[0]['args'][1], $line, $file);
	}
}