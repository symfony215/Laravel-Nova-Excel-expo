<?php

namespace Maatwebsite\LaravelNovaExcel\Http\Controllers;

use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Routing\ResponseFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExcelController extends Controller
{
    use ValidatesRequests;

    /**
     * @param Request         $request
     * @param ResponseFactory $response
     *
     * @return BinaryFileResponse
     */
    public function download(Request $request, ResponseFactory $response): BinaryFileResponse
    {
        $data = $this->validate($request, [
            'path'     => 'required',
            'filename' => 'required',
        ]);

        return $response->download(
            $data['path'],
            $data['filename']
        )->deleteFileAfterSend();
    }
}
