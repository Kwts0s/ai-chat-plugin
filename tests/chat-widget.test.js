const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );
const { webcrypto } = require( 'node:crypto' );

const source = fs.readFileSync( path.join( __dirname, '../assets/js/chat-widget.js' ), 'utf8' )
	.replace( /\}\s*\)\(\);\s*$/, 'globalThis.chatInternals = { Session, API, Transcript }; } )();' );

function storage() {
	const values = new Map();
	return {
		getItem: ( key ) => values.get( key ) || null,
		setItem: ( key, value ) => values.set( key, String( value ) ),
		removeItem: ( key ) => values.delete( key ),
	};
}

function widget( fetch ) {
	const localStorage = storage();
	const sessionStorage = storage();
	const context = {
		aiChatWidgetConfig: {
			enabled: true,
			mode: 'proxy',
			proxyUrl: 'https://example.test/wp-json/ai-chat/v1/chat',
			nonceUrl: 'https://example.test/wp-json/ai-chat/v1/nonce',
			nonce: 'stale',
		},
		document: { readyState: 'loading', addEventListener() {} },
		localStorage,
		sessionStorage,
		fetch,
		crypto: webcrypto,
		AbortController,
		setTimeout,
		clearTimeout,
		console,
	};
	vm.runInNewContext( source, context );
	return { ...context.chatInternals, localStorage, sessionStorage };
}

function jsonResponse( data, status = 200 ) {
	return { ok: status >= 200 && status < 300, status, json: async () => data };
}

test( 'refreshes a stale nonce and forwards the returned history token on the next turn', async () => {
	const requests = [];
	const { Session, API, sessionStorage } = widget( async ( url, options ) => {
		requests.push( { url, options } );
		if ( options.method === 'GET' ) return jsonResponse( { nonce: 'fresh' } );
		const body = JSON.parse( options.body );
		return jsonResponse( [ {
			sessionId: body.sessionId,
			text: 'answer',
			historyAccessToken: 'token-123',
		} ] );
	} );
	Session.init();
	const id = Session.id;
	await API.send( 'first' );
	await API.send( 'second' );
	assert.equal( requests.length, 4 );
	assert.match( requests[0].url, /ai_chat_nonce_request=/ );
	assert.equal( requests[1].options.headers['X-AI-Chat-Nonce'], 'fresh' );
	assert.equal( requests[1].options.headers['X-Chat-History-Token'], undefined );
	assert.equal( requests[3].options.headers['X-Chat-History-Token'], 'token-123' );
	assert.equal( JSON.parse( requests[3].options.body ).sessionId, id );
	assert.equal( sessionStorage.getItem( 'ai_chat_history_access:' + id ), 'token-123' );
} );

test( 'does not resume an old session ID when its history credential is missing', () => {
	const { Session, localStorage } = widget( async () => jsonResponse( {} ) );
	const oldId = '00000000-0000-4000-8000-000000000000';
	localStorage.setItem( Session.key, oldId );
	localStorage.setItem( 'ai_chat_transcript', '[{"role":"user","text":"old"}]' );
	Session.init();
	assert.notEqual( Session.id, oldId );
	assert.equal( localStorage.getItem( 'ai_chat_transcript' ), null );
} );

test( 'does not send a chat POST when a current nonce cannot be obtained', async () => {
	const requests = [];
	const { Session, API } = widget( async ( url, options ) => {
		requests.push( { url, options } );
		return jsonResponse( {}, 503 );
	} );
	Session.init();
	await assert.rejects( API.send( 'hello' ), /temporarily unavailable/ );
	assert.equal( requests.length, 1 );
	assert.equal( requests[0].options.method, 'GET' );
} );
