<?php

namespace Milo\Github\Http;


/**
 * Client which use the file_get_contents() with a HTTP context options.
 *
 * @author  Miloslav Hůla (https://github.com/milo)
 */
class StreamClient extends AbstractClient
{
	/** @var array|NULL */
	private $sslOptions;

	/**
	 * @param  array  SSL context options {@link http://php.net/manual/en/context.ssl.php}
	 */
	public function __construct(array $sslOptions = NULL)
	{
		$this->sslOptions = $sslOptions;
	}


	/**
	 * @return Response
	 *
	 * @throws BadResponseException
	 */
	protected function process(Request $request)
	{
		$headerStr = [];
		foreach ($request->getHeaders() as $name => $value) {
			foreach ((array) $value as $v) {
				$headerStr[] = "$name: $v";
			}
		}

		$options = [
			'http' => [
				'method' => $request->getMethod(),
				'header' => implode("\r\n", $headerStr) . "\r\n",
				'follow_location' => 0,  # Github sets the Location header for 201 code too and redirection is not required for us
				'protocol_version' => 1.1,
				'ignore_errors' => TRUE,
			],
		];

		if (($content = $request->getContent()) !== NULL) {
			$options['http']['content'] = $content;
		}

		if ($this->sslOptions) {
			$options['ssl'] = $this->sslOptions;
		}

		list($code, $headers, $content) = $this->fileGetContents($request->getUrl(), $options);
		return new Response($code, $headers, $content);
	}


	/**
	 * @internal
	 * @param  string
	 * @param  array
	 * @return array
	 *
	 * @throws BadResponseException
	 */
	protected function fileGetContents($url, array $contextOptions)
	{
		$context = stream_context_create($contextOptions);

		$e = NULL;
		set_error_handler(function($severity, $message, $file, $line) use (& $e) {
			$e = new \ErrorException($message, 0, $severity, $file, $line, $e);
		}, E_WARNING);

		$content = file_get_contents($url, FALSE, $context);
		restore_error_handler();

		if (!isset($http_response_header)) {
			throw new BadResponseException('Missing HTTP headers, request failed.', 0, $e);
		}

		if (!isset($http_response_header[0]) || !preg_match('~^HTTP/1[.]. (\d{3})~i', $http_response_header[0], $m)) {
			throw new BadResponseException('HTTP status code is missing.', 0, $e);
		}
		unset($http_response_header[0]);

		$headers = [];
		foreach ($http_response_header as $header) {
			list($name, $value) = explode(': ', $header, 2) + [NULL, NULL];
			$headers[$name] = $value;
		}

		return [$m[1], $headers, $content];
	}

}