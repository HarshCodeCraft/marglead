/**
 * 100% Self-Hosted Multi-Session WhatsApp Web Engine Server (Node.js + Baileys)
 * Marg ERP 9+ CRM & Invoice Dispatching
 * Supports Multi-Tenant / Separate WhatsApp Session per User
 */

const express = require('express');
const QRCode = require('qrcode');
const cors = require('cors');
const fs = require('fs');
const path = require('path');
const { makeWASocket, useMultiFileAuthState, DisconnectReason, Browsers } = require('@whiskeysockets/baileys');

const app = express();
app.use(express.json());
app.use(cors());

const PORT = process.env.PORT || 3005;

// In-Memory map of active user sessions: userId -> sessionObject
const sessions = new Map();

// Helper to get session directory for a specific user
function getAuthDir(userId) {
    const dir = path.join(__dirname, 'auth_info_baileys', `user_${userId}`);
    if (!fs.existsSync(dir)) {
        fs.mkdirSync(dir, { recursive: true });
    }
    return dir;
}

// Migrate legacy single session if present
try {
    const legacyDir = path.join(__dirname, 'auth_info_baileys');
    const legacyCreds = path.join(legacyDir, 'creds.json');
    const user1Dir = path.join(legacyDir, 'user_1');
    if (fs.existsSync(legacyCreds) && !fs.existsSync(user1Dir)) {
        fs.mkdirSync(user1Dir, { recursive: true });
        const files = fs.readdirSync(legacyDir);
        for (const file of files) {
            if (file !== 'user_1' && !file.startsWith('user_')) {
                const src = path.join(legacyDir, file);
                const dest = path.join(user1Dir, file);
                try { fs.renameSync(src, dest); } catch(e) {}
            }
        }
        console.log('Migrated legacy WhatsApp session to user_1');
    }
} catch (e) {
    console.error('Migration notice:', e.message);
}

// Initialize or get a session for a user
async function getOrCreateSession(userId) {
    userId = String(userId || '1');
    if (sessions.has(userId)) {
        return sessions.get(userId);
    }

    const sessionObj = {
        userId: userId,
        qrCodeData: '',
        connectionStatus: 'disconnected', // 'connected', 'scan_qr', 'disconnected'
        pairedPhone: '',
        sock: null,
        isStarting: false
    };
    sessions.set(userId, sessionObj);

    await startUserSession(userId);
    return sessionObj;
}

async function startUserSession(userId) {
    userId = String(userId || '1');
    const sessionObj = sessions.get(userId);
    if (!sessionObj) return;

    if (sessionObj.isStarting) return;
    sessionObj.isStarting = true;

    try {
        const authPath = getAuthDir(userId);
        const { state, saveCreds } = await useMultiFileAuthState(authPath);

        const sock = makeWASocket({
            auth: state,
            browser: Browsers.ubuntu("Chrome")
        });
        sessionObj.sock = sock;

        sock.ev.on('creds.update', saveCreds);

        sock.ev.on('connection.update', async (update) => {
            const { connection, lastDisconnect, qr } = update;

            if (qr) {
                sessionObj.qrCodeData = qr;
                sessionObj.connectionStatus = 'scan_qr';
                console.log(`[User ${userId}] New WhatsApp Web QR Code generated for pairing!`);
            }

            if (connection === 'close') {
                const statusCode = lastDisconnect?.error?.output?.statusCode;
                const isLoggedOut = (statusCode === DisconnectReason.loggedOut || statusCode === 401);
                console.log(`[User ${userId}] Connection closed, statusCode:`, statusCode);
                sessionObj.connectionStatus = 'disconnected';

                if (isLoggedOut) {
                    console.log(`[User ${userId}] ⚠️ Session logged out (401). Clearing credentials...`);
                    try { fs.rmSync(authPath, { recursive: true, force: true }); } catch (e) {}
                    sessionObj.qrCodeData = '';
                    sessionObj.pairedPhone = '';
                    sessionObj.isStarting = false;
                    setTimeout(() => startUserSession(userId), 2000);
                } else {
                    sessionObj.isStarting = false;
                    setTimeout(() => startUserSession(userId), 3000);
                }
            } else if (connection === 'open') {
                console.log(`[User ${userId}] 🎉 SUCCESS: WhatsApp Web Connected & Authenticated Successfully!`);
                sessionObj.connectionStatus = 'connected';
                sessionObj.qrCodeData = '';
                sessionObj.isStarting = false;
                if (sock.user && sock.user.id) {
                    sessionObj.pairedPhone = sock.user.id.split(':')[0];
                }
            }
        });
    } catch (err) {
        console.error(`[User ${userId}] Error starting Baileys engine:`, err);
        sessionObj.isStarting = false;
    }
}

// Auto-restore all existing user sessions on startup
try {
    const baseAuthDir = path.join(__dirname, 'auth_info_baileys');
    if (fs.existsSync(baseAuthDir)) {
        const subDirs = fs.readdirSync(baseAuthDir);
        for (const sub of subDirs) {
            if (sub.startsWith('user_')) {
                const uid = sub.replace('user_', '');
                if (uid) {
                    console.log(`Restoring WhatsApp session for user: ${uid}`);
                    getOrCreateSession(uid);
                }
            }
        }
    }
} catch (e) {
    console.error('Session restore notice:', e.message);
}

// Ensure default user_1 session is ready if no other session exists
getOrCreateSession('1');

// API Endpoints:

// 1. GET /qr?user_id=X
app.get('/qr', async (req, res) => {
    const userId = String(req.query.user_id || req.body?.user_id || '1');
    const sessionObj = await getOrCreateSession(userId);

    if (sessionObj.connectionStatus === 'connected') {
        return res.json({ status: 'connected', message: 'Phone is already paired & connected!', phone: sessionObj.pairedPhone });
    }

    if (sessionObj.qrCodeData) {
        try {
            const qrImageBase64 = await QRCode.toDataURL(sessionObj.qrCodeData);
            return res.json({
                status: 'scan_qr',
                qr: sessionObj.qrCodeData,
                qr_image: qrImageBase64
            });
        } catch (e) {
            return res.json({ status: 'scan_qr', qr: sessionObj.qrCodeData });
        }
    }

    return res.json({ status: 'initializing', message: 'Generating QR code... Please try again in 3 seconds.' });
});

// 2. GET /status?user_id=X
app.get('/status', async (req, res) => {
    const userId = String(req.query.user_id || req.body?.user_id || '1');
    const sessionObj = await getOrCreateSession(userId);

    res.json({
        status: sessionObj.connectionStatus,
        phone_number: sessionObj.pairedPhone,
        user_id: userId,
        engine: 'Self-Hosted Multi-Session Baileys Engine v2.0',
        uptime: process.uptime()
    });
});

// 3. POST /pairing-code
app.post('/pairing-code', async (req, res) => {
    const userId = String(req.body.user_id || req.query.user_id || '1');
    const { phone } = req.body;
    if (!phone) {
        return res.status(400).json({ status: 'error', message: 'Phone number required.' });
    }
    const cleanPhone = phone.replace(/\D/g, '');

    const sessionObj = await getOrCreateSession(userId);
    try {
        if (sessionObj.sock && !sessionObj.sock.authState.creds.registered) {
            const code = await sessionObj.sock.requestPairingCode(cleanPhone);
            return res.json({ status: 'success', pairing_code: code });
        } else if (sessionObj.connectionStatus === 'connected') {
            return res.json({ status: 'connected', message: 'Already connected!', phone: sessionObj.pairedPhone });
        } else {
            return res.status(400).json({ status: 'error', message: 'Engine initializing, please retry.' });
        }
    } catch (e) {
        return res.status(500).json({ status: 'error', message: e.message });
    }
});

// 4. POST /send-message
app.post('/send-message', async (req, res) => {
    const userId = String(req.body.user_id || req.query.user_id || '1');
    const { recipient, message, pdf_url } = req.body;
    if (!recipient || !message) {
        return res.status(400).json({ status: 'error', message: 'Recipient and message required.' });
    }

    const sessionObj = await getOrCreateSession(userId);
    let jid = recipient.replace(/\D/g, '') + '@s.whatsapp.net';

    try {
        console.log(`[User ${userId}] Attempting to send message to ${jid}, connectionStatus: ${sessionObj.connectionStatus}`);
        if (sessionObj.sock && sessionObj.connectionStatus === 'connected') {
            let result;
            if (pdf_url) {
                result = await sessionObj.sock.sendMessage(jid, {
                    document: { url: pdf_url },
                    mimetype: 'application/pdf',
                    fileName: 'Invoice.pdf',
                    caption: message
                });
            } else {
                result = await sessionObj.sock.sendMessage(jid, { text: message });
            }
            console.log(`[User ${userId}] Message sent successfully to ${jid}, ID: ${result?.key?.id}`);
            return res.json({ status: 'success', success: true, message_id: result?.key?.id });
        } else {
            return res.status(503).json({ status: 'error', message: `WhatsApp Web Session for user ${userId} is currently ${sessionObj.connectionStatus}. Please connect WhatsApp in Settings.` });
        }
    } catch (err) {
        console.error(`[User ${userId}] Error sending message to ${jid}:`, err);
        return res.status(500).json({ status: 'error', message: err.message });
    }
});

// 5. POST /logout
app.post('/logout', async (req, res) => {
    const userId = String(req.body.user_id || req.query.user_id || '1');
    const sessionObj = sessions.get(userId);
    try {
        if (sessionObj && sessionObj.sock) {
            await sessionObj.sock.logout();
        }
        if (sessionObj) {
            sessionObj.connectionStatus = 'disconnected';
            sessionObj.pairedPhone = '';
            sessionObj.qrCodeData = '';
        }
        const authPath = getAuthDir(userId);
        try { fs.rmSync(authPath, { recursive: true, force: true }); } catch (e) {}

        res.json({ status: 'success', message: `Session for user ${userId} logged out successfully.` });
    } catch (err) {
        res.json({ status: 'error', message: err.message });
    }
});

app.listen(PORT, () => {
    console.log(`Self-Hosted Multi-Session WhatsApp Web Engine running on port ${PORT}`);
});
