<?php

namespace App\Traits;

use App\Models\Document;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;

trait DocumentTrait
{
    /**
     * Store the Document.
     *
     * @param Request $request
     * @param string|null $prompt
     * @param int $variations
     * @return array
     * @throws GuzzleException
     */
    protected function documentsStore(Request $request, string $prompt = null)
    {
        $response = $this->fetchCompletions($request, $prompt);
        $results = [];
        $i = 1;
        foreach ($response['choices'] as $result) {
            // If the user can still create documents
            if ($request->user()->can('create', ['App\Models\Document']) && $request->user()->can('words', ['App\Models\Document'])) {
                $results[] = $this->documentModel($request, $result, $i);
                $i++;
            }
        }

        return $results;
    }

    /**
     * Store the Document.
     *
     * @param Request $request
     * @param string|null $prompt
     * @param int $variations
     * @return Document
     * @throws GuzzleException
     */
    protected function documentStore(Request $request, string $prompt = null)
    {
        $response = $this->fetchCompletions($request, $prompt);

        return $this->documentModel($request, $response, 0);
    }

    /**
     * @param Request $request
     * @param $result
     * @param $count
     * @return Document
     */
    private function documentModel(Request $request, $result, $count)
    {
        $words = count(array_filter(explode(' ', preg_replace('/[^\w]/ui', ' ', mb_strtolower(trim(($count == 0 ? $result['choices'][0]['message']['content'] : $result['message']['content'])))))));

        $document = new Document;
        $document->name = $request->input('name'). ($count > 1 ? ' (' . $count .')' : '');
        $document->user_id = $request->user()->id;
        $document->template_id = $request->input('template_id') ?? 1;
        $document->result = trim($count == 0 ? $result['choices'][0]['message']['content'] : $result['message']['content']);
        $document->words = $words;
        $document->save();

        $request->user()->documents_month_count += 1;
        $request->user()->documents_total_count += 1;
        $request->user()->words_month_count += $words;
        $request->user()->words_total_count += $words;
        $request->user()->save();

        return $document;
    }

    /**
     * Update the Document.
     *
     * @param Request $request
     * @param Document $document
     * @return Document
     */
    protected function documentUpdate(Request $request, Document $document)
    {
        if ($request->has('name')) {
            $document->name = $request->input('name');
        }

        if ($request->has('favorite')) {
            $document->favorite = $request->input('favorite');
        }

        if ($request->has('result')) {
            $document->result = $request->input('result');
        }

        $document->save();

        return $document;
    }

    /**
     * @param Request $request
     * @param $prompt
     * @return mixed
     * @throws GuzzleException
     */
    private function fetchCompletions(Request $request, $prompt)
    {
        $httpClient = new Client();

        $response = $httpClient->request('POST', 'https://api.openai.com/v1/chat/completions',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . config('settings.openai_key'),
                ],
                'json' => [
                    'model' => config('settings.openai_completions_model'),
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => trim(preg_replace('/(?:\s{2,}+|[^\S ])/', ' ', $prompt))
                        ]
                    ],
                    'temperature' => $request->has('creativity') ? (float) $request->input('creativity') : 0.5,
                    'n' => $request->has('variations') ? (float) $request->input('variations') : 1,
                    'frequency_penalty' => 0,
                    'presence_penalty' => 0,
                    'user' => 'user' . $request->user()->id
                ]
            ]
        );

        return json_decode($response->getBody()->getContents(), true);
    }
}
