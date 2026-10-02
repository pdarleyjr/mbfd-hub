<div class="flex"
    x-data="{
        setPeriod(period) {
            let query = new URLSearchParams(window.location.search)
            if (period === '1_hour') {
                query.delete('period')
            } else {
                query.set('period', period)
            }

            window.location = `${location.pathname}?${query}`
        }
    }"
>
    <button style="min-width: 24px" @click="setPeriod('1_hour')" class="p-1 font-semibold sm:text-lg {{ $period === '1_hour' ? 'text-gray-700 dark:text-gray-300' : 'text-gray-600 dark:text-gray-400'}}">1h</button>
    <button style="min-width: 24px" @click="setPeriod('6_hours')" class="p-1 font-semibold sm:text-lg {{ $period === '6_hours' ? 'text-gray-700 dark:text-gray-300' : 'text-gray-600 dark:text-gray-400'}}">6h</button>
    <button style="min-width: 24px" @click="setPeriod('24_hours')" class="p-1 font-semibold sm:text-lg {{ $period === '24_hours' ? 'text-gray-700 dark:text-gray-300' : 'text-gray-600 dark:text-gray-400'}}">24h</button>
    <button style="min-width: 24px" @click="setPeriod('7_days')" class="p-1 font-semibold sm:text-lg {{ $period === '7_days' ? 'text-gray-700 dark:text-gray-300' : 'text-gray-600 dark:text-gray-400'}}">7d</button>
</div>
