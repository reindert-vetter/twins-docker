<?php
declare(strict_types=1);

namespace App\Domains\Collect\Controllers;

use App\Console\Helpers\Json;
use App\Domains\Collect\Helpers\RequestHelper;
use App\Domains\Collect\Helpers\ResponseHelper;
use App\Domains\Collect\Providers\RequestProvider;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response as ConsumerResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;

/**
 * @author Reindert Vetter
 */
class CollectorController
{
    const REQUEST_MOCKED_PATH = 'examples/response/';

    /**
     * @param Request         $request
     * @param RequestProvider $requestProvider
     * @return \Illuminate\Http\Response
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     */
    public function handle(Request $request, RequestProvider $requestProvider): Response
    {
        $request = RequestHelper::normalizeRequest($request);

        if ($result = $this->tryExample($request)) {
            return $result;
        }

        $clientResponse = $requestProvider->handle(
            $request->method(),
            str_replace('http://', 'https://', $request->url()),
            $request->query->all(),
            $request->getContent(),
            $request->headers->all()
        );

        $this->saveExample($request, $clientResponse);

        $result = new ConsumerResponse(
            (string) $clientResponse->getBody(),
            $clientResponse->getStatusCode(),
            $clientResponse->getHeaders()
        );

        return $result;
    }

    /**
     * @param \Illuminate\Http\Request            $consumerRequest
     * @param \Psr\Http\Message\ResponseInterface $clientResponse
     * @throws \Throwable
     */
    private function saveExample(Request $consumerRequest, ResponseInterface $clientResponse): void
    {
        $body    = (string) $clientResponse->getBody();
        $langIde = Json::isJson($body) ? 'JSON' : 'XML';

        $url = $this->getRegexUrl($consumerRequest);

        $with = [
            "method"  => $consumerRequest->getMethod(),
            "url"     => $url,
            "status"  => $clientResponse->getStatusCode(),
            "body"    => $body,
            //            "body"    => Json::prettyPrint($body),
            "headers" => ResponseHelper::normalizeHeaders($clientResponse->getHeaders(), strlen($body)),
        ];

        $content = "<?php\n\n" . view('body-template')
                ->with($with)
                ->render();
        $content = str_replace('LANG_IDE', "/** @lang $langIde */", $content);

        $path = $this->getFilePath($consumerRequest);
        if (Storage::exists($path)) {
            throw new Exception("Can't create example $path already exist");
        }

        Storage::put($path, $content);
    }

    /**
     * @param \Illuminate\Http\Request $consumerRequest
     * @return \Illuminate\Http\Response
     * @throws Exception
     */
    private function tryExample(Request $consumerRequest): ?Response
    {
        $examples = $this->getExamples();

        $matchExamples = $examples->filter(
            function ($path) use ($consumerRequest) {
                $mock = require(base_path('storage/app/' . $path));
                return call_user_func($mock['when'], $consumerRequest);
            }
        );

        if ($matchExamples->isEmpty()) {
            return null;
        }

        if ($matchExamples->count() > 1) {
            $pathExamples = str_replace(base_path() . self::REQUEST_MOCKED_PATH, '', $matchExamples->pluck('path')->implode(", \n"));
            throw new Exception("Multiple examples have a match: \n" . $pathExamples);
        }

        $path = $matchExamples->first();

        $mock     = require(base_path('storage/app/' . $path));
        $response = new Response(
            $mock['response']['body'],
            $mock['response']['status'],
            ResponseHelper::normalizeHeaders($mock['response']['headers'], strlen($mock['response']['body']))
        );

        return $response->setContent($mock['response']['body']);
    }

    /**
     * @return Collection
     */
    private function getExamples(): Collection
    {
        $dir = self::REQUEST_MOCKED_PATH;

        $files = collect(Storage::allFiles($dir));

        return $files;
    }

    /**
     * @param Request $consumerRequest
     * @return string
     */
    private function getRegexUrl(Request $consumerRequest): string
    {
        $url = $consumerRequest->fullUrl();

        $regexUrl = preg_quote(html_entity_decode($url), '#');
        return str_replace(['https\:', 'http\:'], 'https?\:', $regexUrl);
    }

    /**
     * @param string $value
     * @return string
     */
    private function getSlug(string $value): string
    {
        $value = Str::kebab($value);
        return Str::slug(
            trim(str_replace(['.', '/', '?', '=', '&', 'https', 'http', 'www', 'api.', '/api'], '-', $value), '-')
        );
    }

    /**
     * @param Request $consumerRequest
     * @return string
     */
    private function getFilePath(Request $consumerRequest): string
    {
        preg_match('/(?<service>\w+).\w{2,10}$/', $consumerRequest->getHost(), $match);
        $service  = Str::kebab($match['service']);
        $fileName = $consumerRequest->method() . ' ' . $this->getSlug(pathinfo($consumerRequest->getUri())['basename']);
        $path     = self::REQUEST_MOCKED_PATH . "$service/$fileName.inc";

        return $path;
    }
}
