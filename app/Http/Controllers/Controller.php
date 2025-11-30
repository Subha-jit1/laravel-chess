<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

abstract class Controller
{
    /**
     * Generic Success Response
     *
     * Usage:
     * return $this->sendResponse(
     *     status: true,
     *     message: 'Success message',
     *     data: [],
     *     status_code: 500,
     *     status_to_shown: 200
     * );
     */
    public function sendResponse(
        bool $status,
        string $message = '',
        array $data = [],
        int $status_code = 200,
        ?int $status_to_shown = null
    ): JsonResponse {
        return response()->json(
            [
                'status' => $status,
                'message' => $message,
                'data' => $data,
                'status_code' => $status_code,
            ],
            $status_to_shown ?? $status_code
        );
    }

    /**
     * Generic Error Response
     *
     * Usage:
     * return $this->sendError('Something went wrong');
     */
    public function sendError(
        string $message,
        $error = [], 
        int $status_code = 500,
        array $data = [],
        ?int $status_to_shown = null
    ): JsonResponse { 
        return $this->sendResponse(
            status: false,
            message: $message,
            data: $data,
            status_code: $status_code,
            status_to_shown: $status_to_shown
        );
    }

    /**
     * Validation Error Response (blank for now)
     */
    public function sendValidationError(
        string $message = 'Validation Error',
        array $errors = [],
        int $status_code = 422, 
    ): JsonResponse {
        // YOU SAID → keep this blank for now
        return $this->sendResponse(
            status: false,
            message: $message,
            data: $errors,
            status_code: $status_code, 
        );
    }
}
