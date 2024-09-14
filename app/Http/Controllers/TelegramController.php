<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TelegramController extends Controller
{
    public function handleWebhook(Request $request)
    {
        // Ambil token dari file .env
        $botToken = env('TELEGRAM_BOT_TOKEN'); 

        $update = $request->all();

        if (isset($update['message']['document'])) {
            // Jika user mengirim file
            $fileId = $update['message']['document']['file_id'];
            $chatId = $update['message']['chat']['id'];

            // Dapatkan URL file dari Telegram API
            $fileUrl = "https://api.telegram.org/bot$botToken/getFile?file_id=$fileId";
            $fileResponse = json_decode(file_get_contents($fileUrl), true);

            if (isset($fileResponse['result']['file_path'])) {
                $filePath = $fileResponse['result']['file_path'];
                $fileDownloadUrl = "https://api.telegram.org/file/bot$botToken/$filePath";

                // Download file CSV dari Telegram
                $csvFile = file_get_contents($fileDownloadUrl);
                $localFilePath = storage_path('app/uploads/' . basename($filePath));
                file_put_contents($localFilePath, $csvFile);

                // Proses CSV untuk menghitung jumlah baris yang mengandung setiap keyword
                $keywordCounts = $this->countRowsWithKeywords($localFilePath);

                // Format hasil dan kirim ke user
                $responseMessage = $this->formatResults($keywordCounts);
                $this->sendMessage($chatId, $responseMessage, $botToken);

                // Simpan hasil lengkap ke file CSV
                $fullResultsCsv = $this->generateFullResultsCsv($keywordCounts);
                $fullResultsFilePath = storage_path('app/uploads/full_results.csv');
                file_put_contents($fullResultsFilePath, $fullResultsCsv);

                // Kirim file CSV ke user
                $this->sendDocument($chatId, $fullResultsFilePath, $botToken);

                // Hapus file setelah diproses
                unlink($localFilePath);
                unlink($fullResultsFilePath);
            }
        } else {
            // Jika bukan file, kirim pesan minta upload file
            $chatId = $update['message']['chat']['id'];
            $messageText = "Tolong kirim file CSV untuk diproses.";
            $this->sendMessage($chatId, $messageText, $botToken);
        }

        return response()->json(['status' => 'success']);
    }

    private function countRowsWithKeywords($filePath)
    {
        // Memuat semua keyword dari file
        $keywords = file(storage_path('app/keywords.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $keywordCounts = array_fill_keys($keywords, 0);

        // Buka file CSV dan hitung baris yang mengandung setiap keyword
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
        // Menentukan lebar kolom untuk keyword dan total
        $keywordColumnWidth = 50; // Lebar kolom keyword
        $totalColumnWidth = 5;    // Lebar kolom total
        
        // Inisialisasi total counter
        $totalCount = 0;
        
        // Mengurutkan keyword berdasarkan jumlah hitung (dari yang terbanyak)
        arsort($keywordCounts);

        // Ambil 10 keyword teratas
        $topKeywords = array_slice($keywordCounts, 0, 10, true);

        // Membuat header tabel
        $formattedResults = "```\n";
        $formattedResults .= "Keyword" . str_repeat(' ', $keywordColumnWidth - strlen("Keyword")) . " | Total\n";
        $formattedResults .= str_repeat('=', $keywordColumnWidth + $totalColumnWidth + 3) . "\n";
        
        // Format setiap baris
        foreach ($topKeywords as $keyword => $count) {
            // Menambahkan spasi di akhir keyword untuk memastikan kolom tetap rata
            $keywordPadded = str_pad($keyword, $keywordColumnWidth);
            // Menambahkan spasi di depan count untuk memastikan kolom tetap rata
            $countPadded = str_pad($count, $totalColumnWidth, ' ', STR_PAD_LEFT);
            $formattedResults .= $keywordPadded . " | $countPadded\n";
            
            // Tambah ke total count
            $totalCount += $count;
        }
        
        // Baris total
        $formattedResults .= str_repeat('-', $keywordColumnWidth + $totalColumnWidth + 3) . "\n";
        $formattedResults .= str_pad("Total", $keywordColumnWidth) . " | " . str_pad($totalCount, $totalColumnWidth, ' ', STR_PAD_LEFT) . "\n";
        
        $formattedResults .= "```";
        return $formattedResults;
    }

    private function generateFullResultsCsv($keywordCounts)
    {
        // Menyiapkan file CSV dengan header
        $csvFile = "Keyword,Total\n";
        
        // Format setiap baris ke CSV
        foreach ($keywordCounts as $keyword => $count) {
            // Tambahkan tanda kutip ganda di sekitar keyword dan count
            $csvFile .= '"' . str_replace('"', '""', $keyword) . '",';
            $csvFile .= '"' . str_replace('"', '""', $count) . "\"\n";
        }
        
        return $csvFile;
    }
    


    private function sendDocument($chatId, $filePath, $botToken)
    {
        // URL API untuk mengirim dokumen
        $url = "https://api.telegram.org/bot$botToken/sendDocument";
        
        // Parameter untuk request POST
        $params = [
            'chat_id' => $chatId,
            'document' => new \CURLFile($filePath),
        ];
        
        // Mengirim request POST
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        $response = curl_exec($ch);
        curl_close($ch);
        
        return $response;
    }

    private function sendMessage($chatId, $messageText, $botToken)
    {
        // URL API untuk mengirim pesan dengan mode markdown
        $url = "https://api.telegram.org/bot$botToken/sendMessage";
        
        // Parameter untuk request POST
        $params = [
            'chat_id' => $chatId,
            'text' => $messageText,
            'parse_mode' => 'MarkdownV2' // Menggunakan MarkdownV2 untuk format monospace
        ];
        
        // Mengirim request POST
        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($params),
            ],
        ];
        
        $context  = stream_context_create($options);
        file_get_contents($url, false, $context);
    }
}
