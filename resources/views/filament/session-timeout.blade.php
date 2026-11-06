{{-- Sends an idle admin back to the sign-in page (inactivity timeout). --}}
<script>
    (() => {
        // The server drops the session this long after the last request, so the
        // page counts from its last answer too, and shares that time between tabs:
        // activity in one tab keeps the session, and so the other tabs, alive.
        const lifetimeMs = {{ $lifetime }} * 60 * 1000;
        const loginUrl = @js($loginUrl);
        const logoutUrl = @js($logoutUrl);
        const loginPath = new URL(loginUrl, window.location.href).pathname;
        const storageKey = 'nexusdav.panel.activity';
        let leaving = false
        let lastOwn = Date.now()

        const touch = () => {
            try {
                localStorage.setItem(storageKey, String(Date.now()))
            } catch (error) {
                // Private mode: this tab keeps its own time.
            }

            lastOwn = Date.now()
        }

        const lastActivity = () => {
            try {
                return Math.max(lastOwn, Number(localStorage.getItem(storageKey)) || 0)
            } catch (error) {
                return lastOwn
            }
        }

        const leave = () => {
            if (leaving) {
                return
            }

            leaving = true

            const token = document.querySelector('meta[name="csrf-token"]')?.content ?? ''
            const done = () => window.location.assign(loginUrl)

            // End the session on the server too, in case it is still alive
            // (another tab, or a clock a little off). A 419 or a redirect is fine.
            fetch(logoutUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            }).then(done, done)
        }

        const check = () => {
            if (Date.now() - lastActivity() >= lifetimeMs) {
                leave()
            }
        }

        touch()
        setInterval(check, 10000)
        document.addEventListener('visibilitychange', check)
        window.addEventListener('focus', check)

        document.addEventListener('livewire:init', () => {
            window.Livewire.interceptRequest(({ onResponse, onError, onRedirect }) => {
                onResponse(({ response }) => {
                    if (response.status === 419 || response.status === 401) {
                        return
                    }

                    touch()
                })

                // The server sent a Livewire request to the sign-in page: the
                // session is gone. Go there with the message instead.
                onRedirect(({ url, preventDefault }) => {
                    if (new URL(url, window.location.href).pathname === loginPath) {
                        preventDefault()
                        leave()
                    }
                })

                // Livewire would ask "This page has expired. Refresh?" here.
                onError(({ response, preventDefault }) => {
                    if (response.status === 419 || response.status === 401) {
                        preventDefault()
                        leave()
                    }
                })
            })
        })
    })()
</script>
