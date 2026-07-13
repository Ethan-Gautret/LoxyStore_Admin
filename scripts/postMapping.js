(async () => {
  try {
    const res = await fetch('http://127.0.0.1:8000/api/categories/mapping', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ tds_category: 'COMACCSER', ps_category_id: 1 }),
    });

    console.log('Status:', res.status);
    const txt = await res.text();
    try {
      console.log('JSON:', JSON.stringify(JSON.parse(txt), null, 2));
    } catch (e) {
      console.log('Body:', txt);
    }
  } catch (e) {
    console.error('Error:', e.message);
  }
})();
