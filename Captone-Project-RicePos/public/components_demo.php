<?php
session_start();
// Demo page only; protect behind login if desired
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>UI Components Demo - RicePOS</title>
  <?php include __DIR__ . '/../includes/tailwind_head.php'; ?>
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
</head>
<body class="bg-slate-50 dark:bg-slate-950">
  <div class="min-h-screen">
    <header class="sticky top-0 z-40 bg-white/80 dark:bg-slate-900/80 backdrop-blur border-b border-slate-200 dark:border-slate-800">
      <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
        <div class="font-extrabold tracking-tight text-slate-900 dark:text-slate-100">RicePOS UI Kit</div>
        <div class="flex items-center gap-2">
          <button type="button" onclick="toggleTheme()" class="btn">Toggle Theme</button>
          <button type="button" onclick="uiToast('Saved successfully','success')" class="btn btn-primary">Show Toast</button>
        </div>
      </div>
    </header>
    <main class="max-w-7xl mx-auto px-4 py-6">
      <section class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="card p-4">
          <div class="text-slate-500">Revenue</div>
          <div class="text-2xl font-extrabold">₱125,400</div>
        </div>
        <div class="card p-4">
          <div class="text-slate-500">Transactions</div>
          <div class="text-2xl font-extrabold">342</div>
        </div>
        <div class="card p-4">
          <div class="text-slate-500">Deliveries</div>
          <div class="text-2xl font-extrabold">18</div>
        </div>
        <div class="card p-4">
          <div class="text-slate-500">Low Stock</div>
          <div class="text-2xl font-extrabold">7</div>
        </div>
      </section>
      <section class="card p-4 mb-6">
        <div class="flex items-center justify-between mb-3">
          <h3 class="font-bold">Data Table</h3>
          <div class="flex items-center gap-2">
            <input type="text" placeholder="Search…" class="form-control !h-10" style="width: 220px;">
            <button class="btn btn-secondary">Filter</button>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead class="table-light">
              <tr>
                <th>Product</th>
                <th class="text-end">Price</th>
                <th class="text-end">Stock</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>Special Rice 25kg</td>
                <td class="text-end">₱1,850</td>
                <td class="text-end">44</td>
                <td><span class="badge badge-success">Active</span></td>
                <td class="text-end"><button class="btn btn-sm btn-primary">Edit</button></td>
              </tr>
              <tr>
                <td>Premium Rice 50kg</td>
                <td class="text-end">₱3,650</td>
                <td class="text-end">8</td>
                <td><span class="badge badge-warning">Low</span></td>
                <td class="text-end"><button class="btn btn-sm btn-primary">Edit</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <section class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <form class="card p-4">
          <h3 class="font-bold mb-3">Form</h3>
          <div class="grid md:grid-cols-2 gap-3">
            <div>
              <label class="form-label">Name</label>
              <input class="form-control" placeholder="Product name">
            </div>
            <div>
              <label class="form-label">Price per Sack</label>
              <input type="number" class="form-control" placeholder="0">
            </div>
            <div class="md:col-span-2">
              <label class="form-label">Notes</label>
              <input class="form-control" placeholder="Optional">
            </div>
          </div>
          <div class="mt-3 flex gap-2">
            <button type="button" class="btn btn-success" onclick="uiToast('Saved','success')">Save</button>
            <button type="button" class="btn" onclick="uiConfirm({title:'Discard changes?',icon:'warning'}).then(r=>{ if(r.isConfirmed) uiToast('Discarded','info'); })">Cancel</button>
          </div>
        </form>
        <div class="card p-4">
          <h3 class="font-bold mb-3">Buttons</h3>
          <div class="flex flex-wrap gap-2">
            <button class="btn btn-primary">Primary</button>
            <button class="btn btn-secondary">Secondary</button>
            <button class="btn btn-success">Success</button>
            <button class="btn btn-danger">Danger</button>
            <button class="btn">Default</button>
          </div>
        </div>
      </section>
    </main>
  </div>
</body>
</html>


