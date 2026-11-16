// js/realtime.js
document.addEventListener("DOMContentLoaded", function () {
    // Local XAMPP Node.js Socket.io coordinator only runs on localhost/http
    if (window.location.protocol === 'https:' || !['localhost', '127.0.0.1'].includes(window.location.hostname)) {
        return;
    }
    if (typeof io !== 'function') {
        return;
    }

    // Connect to Node.js server dynamically using the host's hostname
    const socket = io('http://' + window.location.hostname + ':3000');

    console.log('Connecting to Real-time Stock Coordinator...');

    socket.on('connect', () => {
        console.log('Connected to Real-time Stock Coordinator! Socket ID:', socket.id);
    });

    socket.on('disconnect', () => {
        console.log('Disconnected from Real-time Stock Coordinator.');
    });

    // Listen for stock updates from other users' checkouts or approvals
    socket.on('stock_update', (data) => {
        console.log('Received real-time stock update event:', data);
        if (data && data.items) {
            data.items.forEach(item => {
                // 1. Update Inventory Page cards
                const card = document.querySelector(`[data-supply-id="${item.id}"]`);
                if (card) {
                    const qtySpans = Array.from(card.querySelectorAll('span'));
                    const qtySpan = qtySpans.find(el => el.textContent.includes('คงเหลือ:'));
                    
                    if (qtySpan) {
                        qtySpan.textContent = `คงเหลือ: ${item.qty}`;
                        
                        // Apply pulse visual effect
                        qtySpan.classList.add('text-amber-500', 'scale-105', 'font-black');
                        setTimeout(() => {
                            qtySpan.classList.remove('text-amber-500', 'scale-105', 'font-black');
                        }, 2000);
                    }

                    // Rebuild quantities dropdown options
                    const select = document.getElementById(`qty-${item.id}`);
                    if (select) {
                        const currentVal = parseInt(select.value) || 1;
                        select.innerHTML = '';
                        
                        const minOrder = 1;
                        const loopMax = (item.qty > 100) ? 100 : item.qty;
                        
                        if (item.qty > 0) {
                            select.disabled = false;
                            select.classList.remove('cursor-not-allowed', 'opacity-75', 'bg-gray-100');
                            
                            for (let q = minOrder; q <= loopMax; q++) {
                                const opt = document.createElement('option');
                                opt.value = q;
                                opt.textContent = q;
                                select.appendChild(opt);
                            }
                            
                            if (item.qty > 100) {
                                const optMax = document.createElement('option');
                                optMax.value = item.qty;
                                optMax.textContent = `${item.qty} (Max)`;
                                select.appendChild(optMax);
                            }
                            
                            // Restore selected value if still valid
                            if (currentVal <= item.qty) {
                                select.value = currentVal;
                            } else {
                                select.value = item.qty;
                            }
                        } else {
                            // Out of stock behavior
                            select.disabled = true;
                            select.classList.add('cursor-not-allowed', 'opacity-75', 'bg-gray-100');
                            const opt = document.createElement('option');
                            opt.value = 0;
                            opt.textContent = '0';
                            select.appendChild(opt);
                        }
                    }
                    
                    // Toggle overlay card disable class if out of stock
                    const imgContainer = card.querySelector('.h-48.bg-gray-100');
                    if (imgContainer) {
                        const hasOverlay = imgContainer.querySelector('.bg-black\\/70');
                        if (item.qty <= 0) {
                            card.classList.add('opacity-70', 'grayscale');
                            if (!hasOverlay) {
                                const overlay = document.createElement('div');
                                overlay.className = 'absolute inset-0 bg-black/10 flex items-center justify-center z-10';
                                overlay.innerHTML = `<span class="bg-black/70 text-white px-3 py-1 rounded-full text-sm font-bold backdrop-blur-sm shadow-md border border-white/20">วัสดุหมด</span>`;
                                imgContainer.appendChild(overlay);
                            }
                        } else {
                            card.classList.remove('opacity-70', 'grayscale');
                            if (hasOverlay) {
                                hasOverlay.parentElement.remove();
                            }
                        }
                    }
                }

                // 2. Update Cart Page values if open
                const cartRow = document.querySelector(`[data-cart-item-id="${item.id}"]`);
                if (cartRow) {
                    const stockCell = cartRow.querySelector('.stock-cell');
                    if (stockCell) {
                        stockCell.textContent = `${item.qty} ${stockCell.dataset.unit || 'ชิ้น'}`;
                        stockCell.classList.add('text-amber-500', 'font-bold');
                        setTimeout(() => {
                            stockCell.classList.remove('text-amber-500', 'font-bold');
                        }, 2000);
                    }
                    // Trigger cart validation if needed
                    if (typeof checkStockLive === 'function') {
                        checkStockLive();
                    }
                }
            });
        }
    });
});
