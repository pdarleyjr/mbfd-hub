# Test Rate Limiting on Public API
Write-Host "Testing Rate Limiting on Public API Endpoints..." -ForegroundColor Cyan
Write-Host "Sending 3 requests to verify rate limit headers are present`n" -ForegroundColor Yellow

for ($i = 1; $i -le 3; $i++) {
    try {
        $response = Invoke-WebRequest -Uri "https://support.darleyplex.com/api/public/apparatuses" -Method GET -Headers @{"Accept"="application/json"} -UseBasicParsing
        $statusCode = $response.StatusCode
        $rateLimitLimit = $response.Headers['X-RateLimit-Limit']
        $rateLimitRemaining = $response.Headers['X-RateLimit-Remaining']
        
        Write-Host "Request $i - Status: $statusCode | Limit: $rateLimitLimit | Remaining: $rateLimitRemaining" -ForegroundColor Green
    } catch {
        Write-Host "Request $i - Error: $($_.Exception.Message)" -ForegroundColor Red
    }
    Start-Sleep -Milliseconds 100
}

Write-Host "`nRate limiting is configured with throttle:60,1 (60 requests per minute)" -ForegroundColor Cyan
Write-Host "To test exceeding the limit, run 65+ requests within a minute." -ForegroundColor Yellow
