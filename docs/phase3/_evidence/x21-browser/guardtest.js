// KNOWN-POSITIVE VALIDATION: the guard must FAIL against a single-threaded server.
const API = 'http://127.0.0.1:8000';
(async () => {
  const solo0 = Date.now();
  await fetch(API + '/api/terminology').then(r=>r.status).catch(()=>'ERR');
  const solo = Math.max(Date.now() - solo0, 1);
  const N = 8, t0 = Date.now();
  const codes = await Promise.all(Array.from({length:N}, () => fetch(API+'/api/terminology').then(r=>r.status).catch(()=>'ERR')));
  const ms = Date.now() - t0, ratio = ms/solo;
  const answered = codes.every(c=>c!=='ERR');
  const concurrent = answered && ratio < (N/2);
  console.log(`solo=${solo}ms  ${N} concurrent=${ms}ms  ratio=${ratio.toFixed(1)}  verdict=${concurrent?'CONCURRENT (guard PASSES)':'SERIALISED (guard FAILS)'}`);
})();
