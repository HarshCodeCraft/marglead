/**
 * FIXED: 100% Multi-Tenant Isolated WhatsApp Web Engine (Node.js + Baileys)
 * Marg ERP 9+ CRM - Each user_id gets its OWN isolated WhatsApp session
 * Cross-tenant session NEVER shares phone numbers
 * v3.0 - STRICT ISOLATION FIX
 */

const express = require('express');
const QRCode = require('qrcode');
const cors = require('cors');
const fs = require('fs');
const path = require('path');
const { makeWASocket, useMultiFileAuthState, DisconnectReason, Browsers } = require('@whiskeysockets/baileys');

const app = express();
app.use(express.json({ limit: '20mb' }));
app.use(cors());

const PORT = process.env.PORT || 3000;

// In-Memory map: userId (string) -> sessionObject
const sessions = new Map();

// ============================================================
// STRICT: Only return session IF it exists and is connected
// NEVER auto-create a session for unknown user_ids on /status
// ============================================================
function getAuthDir(userId) {
    const dir = path.join(__dirname, 'auth_info_baileys', `user_${userId}`);
    if (!fs.existsSync(dir)) {
        fs.mkdirSync(dir, { recursive: true });
    }
    return dir;
}

// Check if an auth folder with credentials exists for this user
function hasStoredCredentials(userId) {
    const authDir = path.join(__dirname, 'auth_info_baileys', `user_${userId}`);
    const credsFile = path.join(authDir, 'creds.json');
    return fs.existsSync(credsFile);
}

async function startUserSession(userId) {
    userId = String(userId);

    // Do NOT re-init if already running
    if (sessions.has(userId) && sessions.get(userId).isStarting) return;
    if (sessions.has(userId) && sessions.get(userId).connectionStatus === 'connected') return;

    const sessionObj = sessions.get(userId) || {
        userId,
        qrCodeData: '',
        connectionStatus: 'disconnected',
        pairedPhone: '',
        sock: null,
        isStarting: false
    };
    sessions.set(userId, sessionObj);

    if (sessionObj.isStarting) return;
    sessionObj.isStarting = true;

    try {
        const authPath = getAuthDir(userId);
        const { state, saveCreds } = await useMultiFileAuthState(authPath);

        const sock = makeWASocket({
            auth: state,
            browser: Browsers.ubuntu("Chrome"),
            printQRInTerminal: false
        });
        sessionObj.sock = sock;

        sock.ev.on('creds.update', saveCreds);

        sock.ev.on('connection.update', async (update) => {
            const { connection, lastDisconnect, qr } = update;

            if (qr) {
                sessionObj.qrCodeData = qr;
                sessionObj.connectionStatus = 'scan_qr';
                console.log(`[User ${userId}] QR Code generated - awaiting scan`);
            }

            if (connection === 'close') {
                const statusCode = lastDisconnect?.error?.output?.statusCode;
                const isLoggedOut = (statusCode === DisconnectReason.loggedOut || statusCode === 401);
                console.log(`[User ${userId}] Connection closed, code: ${statusCode}`);

                sessionObj.connectionStatus = 'disconnected';
                sessionObj.pairedPhone = '';
                sessionObj.isStarting = false;

                if (isLoggedOut) {
                    console.log(`[User ${userId}] Logged out - clearing credentials`);
                    try { fs.rmSync(authPath, { recursive: true, force: true }); } catch (e) { }
                    sessionObj.qrCodeData = '';

                    // Notify PHP CRM
                    try {
                        fetch(`https://friendlyaisolution.com/api/whatsapp_web_engine.php?action=check_status&user_id=${userId}`).catch(() => { });
                    } catch (e) { }

                    setTimeout(() => startUserSession(userId), 3000);
                } else {
                    setTimeout(() => startUserSession(userId), 4000);
                }

            } else if (connection === 'open') {
                sessionObj.connectionStatus = 'connected';
                sessionObj.qrCodeData = '';
                sessionObj.isStarting = false;

                if (sock.user && sock.user.id) {
                    const phoneRaw = sock.user.id.split(':')[0].split('@')[0];
                    sessionObj.pairedPhone = phoneRaw;
                    console.log(`[User ${userId}] ✅ CONNECTED: Phone ${phoneRaw}`);

                    // Notify PHP CRM of this specific user's connection
                    try {
                        fetch(`https://friendlyaisolution.com/api/whatsapp_web_engine.php?action=check_status&user_id=${userId}`).catch(() => { });
                    } catch (e) { }
                }
            }
        });
    } catch (err) {
        console.error(`[User ${userId}] Engine error:`, err.message);
        sessionObj.isStarting = false;
    }
}

// Auto-restore ONLY users with stored credentials on startup
async function restoreAllSessions() {
    const baseAuthDir = path.join(__dirname, 'auth_info_baileys');
    if (!fs.existsSync(baseAuthDir)) return;

    const subDirs = fs.readdirSync(baseAuthDir);
    for (const sub of subDirs) {
        if (sub.startsWith('user_')) {
            const uid = sub.replace('user_', '');
            const credsFile = path.join(baseAuthDir, sub, 'creds.json');
            if (uid && fs.existsSync(credsFile)) {
                console.log(`[Startup] Restoring session for user: ${uid}`);
                await startUserSession(uid);
            }
        }
    }
}

restoreAllSessions();

// =========================================================
// API ENDPOINTS
// =========================================================

// 1. GET /status?user_id=X
// STRICT: Only returns this user's own session status
// NEVER returns another user's phone number
app.get('/status', async (req, res) => {
    const userId = String(req.query.user_id || req.body?.user_id || '');

    if (!userId) {
        return res.status(400).json({ status: 'error', message: 'user_id required' });
    }

    const sessionObj = sessions.get(userId);

    // STRICT ISOLATION: If no session exists for this user, they are disconnected
    if (!sessionObj) {
        return res.json({
            status: 'disconnected',
            phone_number: null,
            phone: null,
            user_id: userId,
            engine: 'Multi-Session Baileys Engine v3.0 (Strict Isolation)',
            uptime: process.uptime()
        });
    }

    res.json({
        status: sessionObj.connectionStatus,
        phone_number: sessionObj.connectionStatus === 'connected' ? sessionObj.pairedPhone : null,
        phone: sessionObj.connectionStatus === 'connected' ? sessionObj.pairedPhone : null,
        user_id: userId,
        engine: 'Multi-Session Baileys Engine v3.0 (Strict Isolation)',
        uptime: process.uptime()
    });
});

// 2. GET /qr?user_id=X
app.get('/qr', async (req, res) => {
    const userId = String(req.query.user_id || req.body?.user_id || '');

    if (!userId) {
        return res.status(400).json({ status: 'error', message: 'user_id required' });
    }

    // Start session if not already running
    await startUserSession(userId);
    const sessionObj = sessions.get(userId);

    if (!sessionObj) {
        return res.json({ status: 'disconnected', user_id: userId });
    }

    if (sessionObj.connectionStatus === 'connected') {
        return res.json({
            status: 'connected',
            phone: sessionObj.pairedPhone,
            phone_number: sessionObj.pairedPhone,
            user_id: userId
        });
    }

    if (sessionObj.qrCodeData) {
        try {
            const qrImageBase64 = await QRCode.toDataURL(sessionObj.qrCodeData);
            return res.json({
                status: 'scan_qr',
                qr: sessionObj.qrCodeData,
                qr_image: qrImageBase64,
                user_id: userId
            });
        } catch (e) {
            return res.json({ status: 'scan_qr', qr: sessionObj.qrCodeData, user_id: userId });
        }
    }

    return res.json({ status: 'initializing', message: 'Generating QR... retry in 3s', user_id: userId });
});

// 3. POST /pairing-code
app.post('/pairing-code', async (req, res) => {
    const userId = String(req.body.user_id || req.query.user_id || '');
    const { phone } = req.body;

    if (!userId || !phone) {
        return res.status(400).json({ status: 'error', message: 'user_id and phone required.' });
    }

    const cleanPhone = phone.replace(/\D/g, '');
    await startUserSession(userId);
    const sessionObj = sessions.get(userId);

    try {
        if (sessionObj?.connectionStatus === 'connected') {
            return res.json({ status: 'connected', message: 'Already connected!', phone: sessionObj.pairedPhone });
        }
        if (sessionObj?.sock && !sessionObj.sock.authState.creds.registered) {
            const code = await sessionObj.sock.requestPairingCode(cleanPhone);
            return res.json({ status: 'success', pairing_code: code });
        }
        return res.status(400).json({ status: 'error', message: 'Engine initializing, please retry in 3 seconds.' });
    } catch (e) {
        return res.status(500).json({ status: 'error', message: e.message });
    }
});

// 4. POST /send-message
// STRICT: Message sent ONLY through this user's own session
app.post('/send-message', async (req, res) => {
    const userId = String(req.body.user_id || req.query.user_id || '');
    const { recipient, message, pdf_url, media_url, image_url, document_url, media_type, media_position, file_name } = req.body;
    const rawMedia = media_url || image_url || document_url || pdf_url || '';
    const pos = (media_position || 'top').toLowerCase();

    if (!userId) {
        return res.status(400).json({ status: 'error', message: 'user_id required' });
    }
    if (!recipient || (!message && !rawMedia)) {
        return res.status(400).json({ status: 'error', message: 'recipient and either message or media required.' });
    }

    const sessionObj = sessions.get(userId);

    // STRICT: No session = reject dispatch
    if (!sessionObj || sessionObj.connectionStatus !== 'connected') {
        return res.status(503).json({
            status: 'error',
            success: false,
            message: `WhatsApp for user ${userId} is ${sessionObj?.connectionStatus || 'not initialized'}. Please scan QR in Settings.`
        });
    }

    let cleanPhone = String(recipient).replace(/\D/g, '');
    if (cleanPhone.length === 10) cleanPhone = '91' + cleanPhone;
    else if (cleanPhone.length === 11 && cleanPhone.startsWith('0')) cleanPhone = '91' + cleanPhone.substring(1);
    const jid = cleanPhone + '@s.whatsapp.net';

    try {
        console.log(`[User ${userId}] Sending message to ${jid} from phone ${sessionObj.pairedPhone}`);
        let result;

        if (rawMedia) {
            const cleanUrl = rawMedia.split('?')[0];
            const ext = cleanUrl.split('.').pop().toLowerCase();
            const isImage = ['jpg', 'jpeg', 'png', 'webp', 'gif'].includes(ext) || media_type === 'image';
            const isPdf = ext === 'pdf' || media_type === 'pdf';
            const fName = file_name || cleanUrl.split('/').pop() || (isPdf ? 'Document.pdf' : 'Attachment');

            if (isImage) {
                if (pos === 'bottom') {
                    // Position Bottom: Send text message first, then image right after
                    if (message && message.trim()) {
                        await sessionObj.sock.sendMessage(jid, { text: message });
                        await new Promise(r => setTimeout(r, 600));
                    }
                    result = await sessionObj.sock.sendMessage(jid, {
                        image: { url: rawMedia }
                    });
                } else {
                    // Position Top: Send image with text as caption
                    result = await sessionObj.sock.sendMessage(jid, {
                        image: { url: rawMedia },
                        caption: message || ''
                    });
                }
            } else if (isPdf) {
                result = await sessionObj.sock.sendMessage(jid, {
                    document: { url: rawMedia },
                    mimetype: 'application/pdf',
                    fileName: fName,
                    caption: message || ''
                });
            } else {
                // Generic document
                result = await sessionObj.sock.sendMessage(jid, {
                    document: { url: rawMedia },
                    mimetype: 'application/octet-stream',
                    fileName: fName,
                    caption: message || ''
                });
            }
        } else {
            result = await sessionObj.sock.sendMessage(jid, { text: message });
        }
        console.log(`[User ${userId}] ✅ Message sent to ${jid}, ID: ${result?.key?.id}`);
        return res.json({
            status: 'success',
            success: true,
            message_id: result?.key?.id,
            from_phone: sessionObj.pairedPhone,
            user_id: userId
        });
    } catch (err) {
        console.error(`[User ${userId}] Send error:`, err.message);
        return res.status(500).json({ status: 'error', success: false, message: err.message });
    }
});

// 5. POST /logout
app.post('/logout', async (req, res) => {
    const userId = String(req.body.user_id || req.query.user_id || '');
    if (!userId) return res.status(400).json({ status: 'error', message: 'user_id required' });

    const sessionObj = sessions.get(userId);
    try {
        if (sessionObj?.sock) {
            await sessionObj.sock.logout();
        }
        sessions.delete(userId);

        const authPath = path.join(__dirname, 'auth_info_baileys', `user_${userId}`);
        try { fs.rmSync(authPath, { recursive: true, force: true }); } catch (e) { }

        console.log(`[User ${userId}] Logged out & session cleared`);
        res.json({ status: 'success', message: `User ${userId} session cleared.` });
    } catch (err) {
        res.json({ status: 'error', message: err.message });
    }
});

// 6. GET /sessions (admin debug)
app.get('/sessions', (req, res) => {
    const list = [];
    sessions.forEach((s, uid) => {
        list.push({
            user_id: uid,
            status: s.connectionStatus,
            phone: s.connectionStatus === 'connected' ? s.pairedPhone : null
        });
    });
    res.json({ total: list.length, sessions: list });
});

app.listen(PORT, () => {
    console.log(`✅ Multi-Tenant WhatsApp Engine v3.0 (Strict Isolation) running on port ${PORT}`);
    console.log(`Each user_id has its OWN isolated session. Cross-tenant sharing is impossible.`);
});
