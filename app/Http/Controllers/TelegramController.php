<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TelegramController extends Controller
{
    public function handleWebhook(Request $request)
    {
        $botToken = env('TELEGRAM_BOT_TOKEN'); 
        $update = $request->all();

        if (isset($update['message']['document'])) {
            $fileId = $update['message']['document']['file_id'];
            $chatId = $update['message']['chat']['id'];

            $fileUrl = "https://api.telegram.org/bot$botToken/getFile?file_id=$fileId";
            $fileResponse = $this->fetchUrl($fileUrl);

            if ($fileResponse && isset($fileResponse['result']['file_path'])) {
                $filePath = $fileResponse['result']['file_path'];
                $fileDownloadUrl = "https://api.telegram.org/file/bot$botToken/$filePath";

                $csvFile = file_get_contents($fileDownloadUrl);
                $localFilePath = storage_path('app/uploads/' . basename($filePath));
                file_put_contents($localFilePath, $csvFile);

                $keywordCounts = $this->countRowsWithKeywords($localFilePath);
                $responseMessage = $this->formatResults($keywordCounts);
                $this->sendMessage($chatId, $responseMessage, $botToken);

                $fullResultsCsv = $this->generateFullResultsCsv($keywordCounts);
                $fullResultsFilePath = storage_path('app/uploads/full_results.csv');
                file_put_contents($fullResultsFilePath, $fullResultsCsv);

                $this->sendDocument($chatId, $fullResultsFilePath, $botToken);

                unlink($localFilePath);
                unlink($fullResultsFilePath);
            }
        } else {
            $chatId = $update['message']['chat']['id'];
            $messageText = "Tolong kirim file CSV untuk diproses.";
            $this->sendMessage($chatId, $messageText, $botToken);
        }

        return response()->json(['status' => 'success']);
    }

    private function countRowsWithKeywords($filePath)
    {
        $keywords = file(storage_path('app/keywords.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $keywordCounts = array_fill_keys($keywords, 0);

        if (($handle = fopen($filePath, 'r')) !== false) {
            while (($row = fgetcsv($handle)) !== false) {
                $rowString = implode(' ', $row);
                foreach ($keywordCounts as $keyword => &$count) {
                    if (strpos($rowString, $keyword) !== false) {
                        $count++;
                    }
                }
            }
            fclose($handle);
        }

        return $keywordCounts;
    }

    private function formatResults($keywordCounts)
    {
        $keywordColumnWidth = 50;
        $totalColumnWidth = 5;
        $totalCount = 0;
        arsort($keywordCounts);
        $topKeywords = array_slice($keywordCounts, 0, 10, true);

        $formattedResults = "```\n";
        $formattedResults .= "Keyword" . str_repeat(' ', $keywordColumnWidth - strlen("Keyword")) . " | Total\n";
        $formattedResults .= str_repeat('=', $keywordColumnWidth + $totalColumnWidth + 3) . "\n";
        
        foreach ($topKeywords as $keyword => $count) {
            $keywordPadded = str_pad($keyword, $keywordColumnWidth);
            $countPadded = str_pad($count, $totalColumnWidth, ' ', STR_PAD_LEFT);
            $formattedResults .= $keywordPadded . " | $countPadded\n";
            $totalCount += $count;
        }
        
        $formattedResults .= str_repeat('-', $keywordColumnWidth + $totalColumnWidth + 3) . "\n";
        $formattedResults .= str_pad("Total", $keywordColumnWidth) . " | " . str_pad($totalCount, $totalColumnWidth, ' ', STR_PAD_LEFT) . "\n";
        
        $formattedResults .= "```";
        return $formattedResults;
    }

    private function generateFullResultsCsv($keywordCounts)
    {
        $csvFile = "Keyword,Total\n";
        
        foreach ($keywordCounts as $keyword => $count) {
            $csvFile .= '"' . str_replace('"', '""', $keyword) . '",';
            $csvFile .= '"' . str_replace('"', '""', $count) . "\"\n";
        }
        
        return $csvFile;
    }

    private function sendDocument($chatId, $filePath, $botToken)
    {
        $url = "https://api.telegram.org/bot$botToken/sendDocument";
        $params = [
            'chat_id' => $chatId,
            'document' => new \CURLFile($filePath),
        ];

        $response = $this->postRequest($url, $params);

        if (!$response) {
            error_log('Error sending document');
        }

        return $response;
    }

    private function sendMessage($chatId, $messageText, $botToken)
    {
        $url = "https://api.telegram.org/bot$botToken/sendMessage";
        $params = [
            'chat_id' => $chatId,
            'text' => $messageText,
            'parse_mode' => 'MarkdownV2'
        ];

        $response = $this->postRequest($url, $params);

        if (!$response) {
            error_log('Error sending message');
        }

        return $response;
    }

    private function postRequest($url, $params)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode != 200) {
            error_log("HTTP Code $httpCode: $response");
        }

        return json_decode($response, true);
    }

    private function fetchUrl($url)
    {
        $response = file_get_contents($url);
        if ($response === false) {
            error_log("Error fetching URL: $url");
        }

        return json_decode($response, true);
    }
}
