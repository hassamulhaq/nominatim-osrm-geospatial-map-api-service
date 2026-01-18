<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nominatim API Test</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/axios/1.6.0/axios.min.js"></script>
    <style>
        button[type="submit"] {
            padding: 12px;
            background: linear-gradient(135deg, #1b1b1b 0%, #030303 100%);
            color: white;
            border: 1px solid black;
        }
        #searchDropdown {
            background: papayawhip;
        }
    </style>
</head>
<body class="bg-background text-foreground antialiased">
<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <!-- Header -->
    <div class="mb-8 border-b border-border pb-6">
        <h1 class="text-3xl font-semibold tracking-tight">Nominatim API Test</h1>
        <p class="mt-2 text-sm text-muted-foreground">Test geocoding endpoints with real-time responses</p>

        <!-- API Status -->
        <div class="mt-4 flex items-center gap-2 text-sm">
            <div class="flex items-center gap-2">
                <div class="h-2 w-2 rounded-full bg-muted" id="statusDot"></div>
                <span class="text-muted-foreground" id="statusText">Checking...</span>
            </div>
        </div>
    </div>

    <!-- Search Form -->
    <div class="mb-8 rounded-lg border border-dashed border-border p-6">
        <div class="mb-6">
            <h2 class="text-lg font-medium">Search Location</h2>
            <p class="text-sm text-muted-foreground">Find places by name, address, or postcode with live search</p>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="space-y-4">
                <!-- Quick Examples -->
                <div class="rounded-md border border-border bg-muted/50 p-3">
                    <div class="mb-2 text-xs font-medium text-muted-foreground">Quick Examples:</div>
                    <div class="flex flex-wrap gap-2">
                        <button
                                type="button"
                                onclick="fillSearch('London Bridge', 10)"
                                class="inline-flex h-7 items-center justify-center rounded-md border border-input bg-background px-3 text-xs hover:bg-accent"
                        >
                            London Bridge
                        </button>
                        <button
                                type="button"
                                onclick="fillSearch('Buckingham Palace', 5)"
                                class="inline-flex h-7 items-center justify-center rounded-md border border-input bg-background px-3 text-xs hover:bg-accent"
                        >
                            Buckingham Palace
                        </button>
                        <button
                                type="button"
                                onclick="fillSearch('W2 2DS', 15)"
                                class="inline-flex h-7 items-center justify-center rounded-md border border-input bg-background px-3 text-xs hover:bg-accent"
                        >
                            W2 2DS
                        </button>
                    </div>
                </div>

                <form id="searchForm" class="space-y-4">
                    <div class="space-y-2">
                        <label for="searchQuery" class="text-sm font-medium">Query</label>
                        <div class="relative">
                            <input
                                    type="text"
                                    id="searchQuery"
                                    placeholder="London Bridge, W2 2DS, etc."
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                                    required
                                    autocomplete="off"
                            />
                            <!-- Dropdown for live search results -->
                            <div id="searchDropdown"
                                 class="absolute z-10 mt-1 hidden w-full rounded-md border border-border bg-background shadow-lg">
                                <div class="max-h-60 overflow-auto p-2">
                                    <!-- Results will be inserted here -->
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label for="searchLimit" class="text-sm font-medium">Limit</label>
                        <input
                                type="number"
                                id="searchLimit"
                                value="10"
                                min="1"
                                max="50"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                        />
                    </div>

                    <button
                            type="submit"
                            class="inline-flex h-9 items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                    >
                        Search
                    </button>
                </form>
            </div>

            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <label class="text-sm font-medium">Response</label>
                    <span class="text-xs text-muted-foreground" id="searchTime"></span>
                </div>
                <div id="searchResults" class="min-h-[300px] rounded-md border border-border bg-muted/50 p-4 text-sm">
                    <div class="text-muted-foreground">Results will appear here</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Reverse Geocode Form -->
    <div class="mb-8 rounded-lg border border-dashed border-border p-6">
        <div class="mb-6">
            <h2 class="text-lg font-medium">Reverse Geocoding</h2>
            <p class="text-sm text-muted-foreground">Get address from coordinates</p>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="space-y-4">
                <!-- Quick Examples -->
                <div class="rounded-md border border-border bg-muted/50 p-3">
                    <div class="mb-2 text-xs font-medium text-muted-foreground">Quick Examples:</div>
                    <div class="flex flex-wrap gap-2">
                        <button
                                type="button"
                                onclick="fillReverse(51.5074, -0.1278)"
                                class="inline-flex h-7 items-center justify-center rounded-md border border-input bg-background px-3 text-xs hover:bg-accent"
                        >
                            Westminster
                        </button>
                        <button
                                type="button"
                                onclick="fillReverse(51.501364, -0.14189)"
                                class="inline-flex h-7 items-center justify-center rounded-md border border-input bg-background px-3 text-xs hover:bg-accent"
                        >
                            Buckingham Palace
                        </button>
                        <button
                                type="button"
                                onclick="fillReverse(51.5055, -0.0754)"
                                class="inline-flex h-7 items-center justify-center rounded-md border border-input bg-background px-3 text-xs hover:bg-accent"
                        >
                            Tower Bridge
                        </button>
                    </div>
                </div>

                <form id="reverseForm" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label for="reverseLat" class="text-sm font-medium">Latitude</label>
                            <input
                                    type="number"
                                    id="reverseLat"
                                    step="0.000001"
                                    placeholder="51.5074"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                                    required
                            />
                        </div>

                        <div class="space-y-2">
                            <label for="reverseLon" class="text-sm font-medium">Longitude</label>
                            <input
                                    type="number"
                                    id="reverseLon"
                                    step="0.000001"
                                    placeholder="-0.1278"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                                    required
                            />
                        </div>
                    </div>

                    <button
                            type="submit"
                            class="inline-flex h-9 items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                    >
                        Get Address
                    </button>
                </form>
            </div>

            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <label class="text-sm font-medium">Response</label>
                    <span class="text-xs text-muted-foreground" id="reverseTime"></span>
                </div>
                <div id="reverseResults" class="min-h-[300px] rounded-md border border-border bg-muted/50 p-4 text-sm">
                    <div class="text-muted-foreground">Results will appear here</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Health Check Form -->
    <div class="mb-8 rounded-lg border border-dashed border-border p-6">
        <div class="mb-6">
            <h2 class="text-lg font-medium">API Health & Status</h2>
            <p class="text-sm text-muted-foreground">Check service health and statistics</p>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="space-y-4">
                <!-- Quick Examples -->
                <div class="rounded-md border border-border bg-muted/50 p-3">
                    <div class="mb-2 text-xs font-medium text-muted-foreground">Quick Examples:</div>
                    <div class="flex flex-wrap gap-2">
                        <button
                                type="button"
                                onclick="fillHealth('/health')"
                                class="inline-flex h-7 items-center justify-center rounded-md border border-input bg-background px-3 text-xs hover:bg-accent"
                        >
                            Health Check
                        </button>
                        <button
                                type="button"
                                onclick="fillHealth('/status')"
                                class="inline-flex h-7 items-center justify-center rounded-md border border-input bg-background px-3 text-xs hover:bg-accent"
                        >
                            Statistics
                        </button>
                        <button
                                type="button"
                                onclick="fillHealth('/')"
                                class="inline-flex h-7 items-center justify-center rounded-md border border-input bg-background px-3 text-xs hover:bg-accent"
                        >
                            API Info
                        </button>
                    </div>
                </div>

                <form id="healthForm" class="space-y-4">
                    <div class="space-y-2">
                        <label for="healthEndpoint" class="text-sm font-medium">Endpoint</label>
                        <select
                                id="healthEndpoint"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                        >
                            <option value="/health">Health Check</option>
                            <option value="/status">Database Statistics</option>
                            <option value="/">API Information</option>
                        </select>
                    </div>

                    <button
                            type="submit"
                            class="inline-flex h-9 items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                    >
                        Check Status
                    </button>
                </form>
            </div>

            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <label class="text-sm font-medium">Response</label>
                    <span class="text-xs text-muted-foreground" id="healthTime"></span>
                </div>
                <div id="healthResults" class="min-h-[300px] rounded-md border border-border bg-muted/50 p-4 text-sm">
                    <div class="text-muted-foreground">Results will appear here</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Custom Test Form -->
    <div class="mb-8 rounded-lg border border-dashed border-border p-6">
        <div class="mb-6">
            <h2 class="text-lg font-medium">Custom Request</h2>
            <p class="text-sm text-muted-foreground">Test any endpoint with custom parameters</p>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="space-y-4">
                <!-- Quick Examples -->
                <div class="rounded-md border border-border bg-muted/50 p-3">
                    <div class="mb-2 text-xs font-medium text-muted-foreground">Quick Examples:</div>
                    <div class="flex flex-wrap gap-2">
                        <button
                                type="button"
                                onclick="fillCustom('/test.php')"
                                class="inline-flex h-7 items-center justify-center rounded-md border border-input bg-background px-3 text-xs hover:bg-accent"
                        >
                            Test Page
                        </button>
                        <button
                                type="button"
                                onclick="fillCustom('/?q=Hyde Park&limit=3')"
                                class="inline-flex h-7 items-center justify-center rounded-md border border-input bg-background px-3 text-xs hover:bg-accent"
                        >
                            Hyde Park Search
                        </button>
                        <button
                                type="button"
                                onclick="fillCustom('/?lat=51.5133&lon=-0.0889')"
                                class="inline-flex h-7 items-center justify-center rounded-md border border-input bg-background px-3 text-xs hover:bg-accent"
                        >
                            City of London
                        </button>
                    </div>
                </div>

                <form id="customForm" class="space-y-4">
                    <div class="space-y-2">
                        <label for="customEndpoint" class="text-sm font-medium">Endpoint</label>
                        <input
                                type="text"
                                id="customEndpoint"
                                placeholder="/test.php"
                                value="/test.php"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                        />
                    </div>

                    <button
                            type="submit"
                            class="inline-flex h-9 items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                    >
                        Send Request
                    </button>
                </form>
            </div>

            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <label class="text-sm font-medium">Response</label>
                    <span class="text-xs text-muted-foreground" id="customTime"></span>
                </div>
                <div id="customResults" class="min-h-[300px] rounded-md border border-border bg-muted/50 p-4 text-sm">
                    <div class="text-muted-foreground">Results will appear here</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const API_BASE = 'http://localhost:8181';
    let searchTimeout;

    // Check API status on load
    checkAPIStatus();

    // Form handlers
    document.getElementById('searchForm').addEventListener('submit', handleSearch);
    document.getElementById('reverseForm').addEventListener('submit', handleReverse);
    document.getElementById('healthForm').addEventListener('submit', handleHealth);
    document.getElementById('customForm').addEventListener('submit', handleCustom);

    // Live search handler
    const searchInput = document.getElementById('searchQuery');
    searchInput.addEventListener('input', handleLiveSearch);

    // Close dropdown when clicking outside
    document.addEventListener('click', (e) => {
        if (!e.target.closest('#searchQuery') && !e.target.closest('#searchDropdown')) {
            document.getElementById('searchDropdown').classList.add('hidden');
        }
    });

    async function checkAPIStatus() {
        try {
            const response = await axios.get(`${API_BASE}/health`);
            document.getElementById('statusDot').className = 'h-2 w-2 rounded-full bg-green-500';
            document.getElementById('statusText').textContent = 'API Online';
        } catch (error) {
            document.getElementById('statusDot').className = 'h-2 w-2 rounded-full bg-red-500';
            document.getElementById('statusText').textContent = 'API Offline';
        }
    }

    function handleLiveSearch(e) {
        clearTimeout(searchTimeout);
        const query = e.target.value.trim();

        if (query.length < 2) {
            document.getElementById('searchDropdown').classList.add('hidden');
            return;
        }

        searchTimeout = setTimeout(async () => {
            try {
                const response = await axios.get(`${API_BASE}/`, {
                    params: {q: query, limit: 5}
                });
                displayDropdownResults(response.data);
            } catch (error) {
                document.getElementById('searchDropdown').classList.add('hidden');
            }
        }, 300);
    }

    function displayDropdownResults(data) {
        const dropdown = document.getElementById('searchDropdown');
        const dropdownContent = dropdown.querySelector('div');

        if (!Array.isArray(data) || data.length === 0) {
            dropdown.classList.add('hidden');
            return;
        }

        let html = '';
        data.forEach(item => {
            html += `
                    <button
                        type="button"
                        onclick="selectDropdownItem('${item.name.replace(/'/g, "\\'")}', ${item.lat}, ${item.lon})"
                        class="w-full rounded-md px-3 py-2 text-left text-sm hover:bg-accent"
                    >
                        <div class="font-medium">${item.name || 'Unnamed'}</div>
                        <div class="text-xs text-muted-foreground truncate">${item.display_name}</div>
                    </button>
                `;
        });

        dropdownContent.innerHTML = html;
        dropdown.classList.remove('hidden');
    }

    window.selectDropdownItem = function (name, lat, lon) {
        document.getElementById('searchQuery').value = name;
        document.getElementById('searchDropdown').classList.add('hidden');
        // Optionally trigger search
        document.getElementById('searchForm').dispatchEvent(new Event('submit'));
    };

    async function handleSearch(e) {
        e.preventDefault();
        const query = document.getElementById('searchQuery').value;
        const limit = document.getElementById('searchLimit').value;
        const resultsDiv = document.getElementById('searchResults');
        const timeSpan = document.getElementById('searchTime');

        resultsDiv.innerHTML = '<div class="text-muted-foreground">Loading...</div>';

        try {
            const startTime = Date.now();
            const response = await axios.get(`${API_BASE}/`, {
                params: {q: query, limit: limit}
            });
            const duration = Date.now() - startTime;

            timeSpan.textContent = `${duration}ms`;
            displaySearchResults(response.data, resultsDiv);
        } catch (error) {
            displayError(error, resultsDiv);
        }
    }

    async function handleReverse(e) {
        e.preventDefault();
        const lat = document.getElementById('reverseLat').value;
        const lon = document.getElementById('reverseLon').value;
        const resultsDiv = document.getElementById('reverseResults');
        const timeSpan = document.getElementById('reverseTime');

        resultsDiv.innerHTML = '<div class="text-muted-foreground">Loading...</div>';

        try {
            const startTime = Date.now();
            const response = await axios.get(`${API_BASE}/`, {
                params: {lat: lat, lon: lon}
            });
            const duration = Date.now() - startTime;

            timeSpan.textContent = `${duration}ms`;
            displaySearchResults(response.data, resultsDiv);
        } catch (error) {
            displayError(error, resultsDiv);
        }
    }

    async function handleHealth(e) {
        e.preventDefault();
        const endpoint = document.getElementById('healthEndpoint').value;
        const resultsDiv = document.getElementById('healthResults');
        const timeSpan = document.getElementById('healthTime');

        resultsDiv.innerHTML = '<div class="text-muted-foreground">Loading...</div>';

        try {
            const startTime = Date.now();
            const response = await axios.get(`${API_BASE}${endpoint}`);
            const duration = Date.now() - startTime;

            timeSpan.textContent = `${duration}ms`;
            displayJSON(response.data, resultsDiv);
        } catch (error) {
            displayError(error, resultsDiv);
        }
    }

    async function handleCustom(e) {
        e.preventDefault();
        const endpoint = document.getElementById('customEndpoint').value;
        const resultsDiv = document.getElementById('customResults');
        const timeSpan = document.getElementById('customTime');

        resultsDiv.innerHTML = '<div class="text-muted-foreground">Loading...</div>';

        try {
            const startTime = Date.now();
            const response = await axios.get(`${API_BASE}${endpoint}`);
            const duration = Date.now() - startTime;

            timeSpan.textContent = `${duration}ms`;
            displayJSON(response.data, resultsDiv);
        } catch (error) {
            displayError(error, resultsDiv);
        }
    }

    function displaySearchResults(data, container) {
        if (!Array.isArray(data) || data.length === 0) {
            container.innerHTML = '<div class="text-muted-foreground">No results found</div>';
            return;
        }

        let html = `<div class="space-y-3">`;
        data.forEach((item, index) => {
            html += `
                    <div class="rounded-md border border-border bg-background p-3">
                        <div class="font-medium">${item.name || 'Unnamed'}</div>
                        <div class="mt-1 text-xs text-muted-foreground">${item.display_name}</div>
                        <div class="mt-2 flex gap-4 text-xs text-muted-foreground">
                            <span>${item.lat}, ${item.lon}</span>
                            <span>${item.type}</span>
                        </div>
                    </div>
                `;
        });
        html += `</div>`;
        container.innerHTML = html;
    }

    function displayJSON(data, container) {
        container.innerHTML = `<pre class="overflow-auto text-xs">${JSON.stringify(data, null, 2)}</pre>`;
    }

    function displayError(error, container) {
        const message = error.response?.data?.error || error.message;
        container.innerHTML = `
                <div class="rounded-md border border-destructive bg-destructive/10 p-3 text-sm text-destructive">
                    ${message}
                </div>
            `;
    }

    window.fillSearch = function (query, limit) {
        document.getElementById('searchQuery').value = query;
        document.getElementById('searchLimit').value = limit;
    };

    window.fillReverse = function (lat, lon) {
        document.getElementById('reverseLat').value = lat;
        document.getElementById('reverseLon').value = lon;
    };

    window.fillHealth = function (endpoint) {
        document.getElementById('healthEndpoint').value = endpoint;
    };

    window.fillCustom = function (endpoint) {
        document.getElementById('customEndpoint').value = endpoint;
    };
</script>
</body>
</html>