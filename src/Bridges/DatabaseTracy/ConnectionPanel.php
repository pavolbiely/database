<?php

/**
 * This file is part of the Nette Framework (http://nette.org)
 * Copyright (c) 2004 David Grudl (http://davidgrudl.com)
 */

namespace Nette\Bridges\DatabaseTracy;

use Nette,
	Nette\Database\Helpers,
	Tracy;


/**
 * Debug panel for Nette\Database.
 *
 * @author     David Grudl
 */
class ConnectionPanel extends Nette\Object implements Tracy\IBarPanel
{
	/** @var int */
	public $maxQueries = 100;

	/** @var int logged time */
	private $totalTime = 0;

	/** @var int */
	private $count = 0;

	/** @var array */
	private $queries = array();

	/** @var string */
	public $name;

	/** @var bool|string explain queries? */
	public $explain = TRUE;

	/** @var bool */
	public $disabled = FALSE;


	public function __construct(Nette\Database\Connection $connection)
	{
		$connection->onQuery[] = array($this, 'logQuery');
	}


	public function logQuery(Nette\Database\Connection $connection, $result)
	{
		if ($this->disabled) {
			return;
		}
		$this->count++;

		$source = NULL;
		$trace = $result instanceof \PDOException ? $result->getTrace() : debug_backtrace(PHP_VERSION_ID >= 50306 ? DEBUG_BACKTRACE_IGNORE_ARGS : FALSE);
		foreach ($trace as $row) {
			if (isset($row['file']) && is_file($row['file']) && !Tracy\Debugger::getBluescreen()->isCollapsed($row['file'])) {
				if ((isset($row['function']) && strpos($row['function'], 'call_user_func') === 0)
					|| (isset($row['class']) && is_subclass_of($row['class'], '\\Nette\\Database\\Connection'))
				) {
					continue;
				}
				$source = array($row['file'], (int) $row['line']);
				break;
			}
		}
		if ($result instanceof Nette\Database\ResultSet) {
			$this->totalTime += $result->getTime();
			if ($this->count < $this->maxQueries) {
				$this->queries[] = array($connection, $result->getQueryString(), $result->getParameters(), $source, $result->getTime(), $result->getRowCount(), NULL);
			}

		} elseif ($result instanceof \PDOException && $this->count < $this->maxQueries) {
			$this->queries[] = array($connection, $result->queryString, NULL, $source, NULL, NULL, $result->getMessage());
		}
	}


	public static function renderException($e)
	{
		if (!$e instanceof \PDOException) {
			return;
		}
		if (isset($e->queryString)) {
			$sql = $e->queryString;

		} elseif ($item = Tracy\Helpers::findTrace($e->getTrace(), 'PDO::prepare')) {
			$sql = $item['args'][0];
		}
		return isset($sql) ? array(
			'tab' => 'SQL',
			'panel' => Helpers::dumpSql($sql),
		) : NULL;
	}


	public function getTab()
	{
		return '<span title="Nette\\Database ' . htmlSpecialChars($this->name, ENT_QUOTES, 'UTF-8') . '">'
			. '<img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAQAAAC1+jfqAAAABGdBTUEAAK/INwWK6QAAABl0RVh0U29mdHdhcmUAQWRvYmUgSW1hZ2VSZWFkeXHJZTwAAAEYSURBVBgZBcHPio5hGAfg6/2+R980k6wmJgsJ5U/ZOAqbSc2GnXOwUg7BESgLUeIQ1GSjLFnMwsKGGg1qxJRmPM97/1zXFAAAAEADdlfZzr26miup2svnelq7d2aYgt3rebl585wN6+K3I1/9fJe7O/uIePP2SypJkiRJ0vMhr55FLCA3zgIAOK9uQ4MS361ZOSX+OrTvkgINSjS/HIvhjxNNFGgQsbSmabohKDNoUGLohsls6BaiQIMSs2FYmnXdUsygQYmumy3Nhi6igwalDEOJEjPKP7CA2aFNK8Bkyy3fdNCg7r9/fW3jgpVJbDmy5+PB2IYp4MXFelQ7izPrhkPHB+P5/PjhD5gCgCenx+VR/dODEwD+A3T7nqbxwf1HAAAAAElFTkSuQmCC" />'
			. $this->count . ' ' . ($this->count === 1 ? 'query' : 'queries')
			. ($this->totalTime ? sprintf(' / %0.1f ms', $this->totalTime * 1000) : '')
			. '</span>';
	}


	public function getPanel()
	{
		$this->disabled = TRUE;
		$s = '';
		foreach ($this->queries as $query) {
			list($connection, $sql, $params, $source, $time, $rows, $error) = $query;

			$explain = NULL; // EXPLAIN is called here to work SELECT FOUND_ROWS()
			if (!$error && $this->explain && preg_match('#\s*\(?\s*SELECT\s#iA', $sql)) {
				try {
					$cmd = is_string($this->explain) ? $this->explain : 'EXPLAIN';
					$explain = $connection->queryArgs("$cmd $sql", $params)->fetchAll();
				} catch (\PDOException $e) {}
			}

			$s .= '<tr><td>';
			if ($error) {
				$s .= '<span title="' . htmlSpecialChars($error, ENT_IGNORE | ENT_QUOTES, 'UTF-8') . '">ERROR</span>';
			} elseif ($time !== NULL) {
				$s .= sprintf('%0.3f', $time * 1000);
			}
			if ($explain) {
				static $counter;
				$counter++;
				$s .= "<br /><a class='tracy-toggle tracy-collapsed' href='#nette-DbConnectionPanel-row-$counter'>explain</a>";
			}

			$s .= '</td><td class="nette-DbConnectionPanel-sql">' . Helpers::dumpSql($sql, $params, $connection);
			if ($explain) {
				$s .= "<table id='nette-DbConnectionPanel-row-$counter' class='tracy-collapsed'><tr>";
				foreach ($explain[0] as $col => $foo) {
					$s .= '<th>' . htmlSpecialChars($col, ENT_NOQUOTES, 'UTF-8') . '</th>';
				}
				$s .= '</tr>';
				foreach ($explain as $row) {
					$s .= '<tr>';
					foreach ($row as $col) {
						$s .= '<td>' . htmlSpecialChars($col, ENT_NOQUOTES, 'UTF-8') . '</td>';
					}
					$s .= '</tr>';
				}
				$s .= '</table>';
			}
			if ($source) {
				$s .= substr_replace(Tracy\Helpers::editorLink($source[0], $source[1]), ' class="nette-DbConnectionPanel-source"', 2, 0);
			}

			$s .= '</td><td>' . $rows . '</td></tr>';
		}

		return $this->count ?
			'<style class="tracy-debug"> #tracy-debug td.nette-DbConnectionPanel-sql { background: white !important }
			#tracy-debug .nette-DbConnectionPanel-source { color: #BBB !important } </style>
			<h1 title="' . htmlSpecialChars($connection->getDsn(), ENT_QUOTES, 'UTF-8') . '">Queries: ' . $this->count
			. ($this->totalTime ? sprintf(', time: %0.3f ms', $this->totalTime * 1000) : '') . ', ' . htmlSpecialChars($this->name, ENT_NOQUOTES, 'UTF-8') . '</h1>
			<div class="tracy-inner nette-DbConnectionPanel">
			<table>
				<tr><th>Time&nbsp;ms</th><th>SQL Query</th><th>Rows</th></tr>' . $s . '
			</table>'
			. (count($this->queries) < $this->count ? '<p>...and more</p>' : '')
			. '</div>' : '';
	}

}
