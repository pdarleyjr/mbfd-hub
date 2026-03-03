<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="manifest" href="/site.webmanifest">
    <link rel="shortcut icon" href="/favicon.ico">
    <meta name="theme-color" content="#1f2937">
    <title>Pump Simulator | MBFD Training</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        gray: {
                            750: '#2d3748',
                            850: '#1a202c',
                            950: '#171923',
                        }
                    }
                }
            }
        }
    </script>
    @vite(['resources/js/pump-simulator/main.tsx'])
</head>
<body class="bg-gray-900">
    <div id="pump-simulator-root"></div>
</body>
</html>
