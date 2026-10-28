<?php
declare(strict_types=1);

const IDLE_TIMEOUT = 900;

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', (string) IDLE_TIMEOUT);

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

if (isset($_SESSION['admin_id']) &&
    time() - ($_SESSION['last_activity'] ?? 0) > IDLE_TIMEOUT) {
    $_SESSION = [];
    session_destroy();
    session_start();
}

if (!isset($_SESSION['admin_id'], $_SESSION['admin_username'])) {
    header('Location: index.html');
    exit;
}

header('Cache-Control: no-store, private');
$_SESSION['last_activity'] = time();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$username = htmlspecialchars((string) $_SESSION['admin_username'], ENT_QUOTES, 'UTF-8');
$csrfToken = json_encode(
    $_SESSION['csrf'],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard Admin Coffeeshop</title>
  <link rel="stylesheet" href="style.css">
</head>
<body class="dashboard-page">
  <div class="dashboard">
    <aside class="sidebar">
      <a class="sidebar-brand" href="#overview">COFFEESHOP ADMIN</a>
      <p class="nav-label">MENU</p>
      <nav aria-label="Navigasi dashboard">
        <a class="nav-link" href="#overview" data-view="overview">Ringkasan</a>
        <a class="nav-link" href="#transactions" data-view="transactions">Riwayat transaksi</a>
        <a class="nav-link" href="#products" data-view="products">Kopi</a>
      </nav>
      <p class="sidebar-note">Kelola data coffeeshop dari satu tempat.</p>
    </aside>

    <div class="workspace">
      <header class="topbar">
        <div class="topbar-brand">
          <span class="mobile-mark">A</span>
          <p class="topbar-title">Ringkasan</p>
        </div>
        <div class="account">
          <span class="account-label">Login sebagai</span>
          <strong><?= $username ?></strong>
          <button id="logout-button" class="logout-button" type="button">Keluar</button>
        </div>
      </header>

      <main class="content">
        <section class="view" id="view-overview">
          <h1 class="page-heading">Ringkasan</h1>
          <p class="page-description">Ikhtisar data coffeeshop Anda.</p>
          <div class="stats">
            <article class="stat-card">
              <p class="stat-label">Total transaksi</p>
              <p id="stat-transactions" class="stat-value">—</p>
            </article>
            <article class="stat-card">
              <p class="stat-label">Jumlah kopi</p>
              <p id="stat-products" class="stat-value">—</p>
            </article>
            <article class="stat-card">
              <p class="stat-label">Total stok tersedia</p>
              <p id="stat-stock" class="stat-value">—</p>
            </article>
            <article class="stat-card" aria-live="polite">
              <p class="stat-label">Total pemasukan transaksi selesai</p>
              <p id="stat-revenue" class="stat-value">—</p>
            </article>
          </div>
          <section class="panel">
            <div class="panel-title-row">
              <h2 class="panel-heading">Transaksi terbaru</h2>
              <a class="text-link" href="#transactions" data-view="transactions">Lihat semua</a>
            </div>
            <div id="recent-transactions" class="empty-state">Memuat transaksi...</div>
          </section>
        </section>

        <section class="view" id="view-transactions" hidden>
          <div class="page-title-row">
            <div>
              <h1 class="page-heading">Riwayat transaksi</h1>
              <p class="page-description">Lihat dan kelola transaksi yang tercatat.</p>
            </div>
            <button id="show-transaction-form" class="small-button" type="button">Tambah transaksi</button>
          </div>
          <form id="transaction-create-form" class="panel form-panel" hidden>
            <h2 class="panel-heading">Transaksi baru</h2>
            <div class="form-grid">
              <label>Kopi
                <select name="product_id" id="transaction-product" required>
                  <option value="">Pilih kopi</option>
                </select>
              </label>
              <label>Jumlah
                <input name="quantity" type="number" min="1" step="1" value="1" required>
              </label>
            </div>
            <p id="transaction-product-info" class="form-hint"></p>
            <p id="transaction-create-message" class="error-message" role="alert"></p>
            <div class="form-actions">
              <button class="small-button" type="submit">Simpan transaksi</button>
              <button id="cancel-transaction-form" class="small-button secondary-button" type="button">Batal</button>
            </div>
          </form>
          <section class="panel">
            <div class="table-wrap">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>ID</th><th>Tanggal</th><th>Kopi</th><th>Jumlah</th>
                    <th>Total</th><th>Status</th><th>Aksi</th>
                  </tr>
                </thead>
                <tbody id="transactions-body">
                  <tr><td colspan="7" class="table-message">Memuat transaksi...</td></tr>
                </tbody>
              </table>
            </div>
          </section>
        </section>

        <section class="view" id="view-products" hidden>
          <div class="page-title-row">
            <div>
              <h1 class="page-heading">Kopi</h1>
              <p class="page-description">Lihat stok dan tambahkan kopi baru.</p>
            </div>
            <button id="show-product-form" class="small-button" type="button">Tambah kopi</button>
          </div>

          <form id="product-form" class="panel form-panel" hidden>
            <h2 class="panel-heading">Kopi baru</h2>
            <div class="form-grid">
              <label>Nama kopi
                <input name="name" type="text" maxlength="150" required>
              </label>
              <label>Kategori
                <input name="category" type="text" maxlength="100">
              </label>
              <label>Stok
                <input name="stock" type="number" min="0" step="1" required>
              </label>
              <label>Harga
                <input name="price" type="number" min="0" step="0.01" required>
              </label>
            </div>
            <div class="form-actions">
              <button class="small-button" type="submit">Simpan kopi</button>
              <button id="cancel-product-form" class="small-button secondary-button" type="button">Batal</button>
            </div>
          </form>

          <section class="panel">
            <div class="table-wrap">
              <table class="data-table">
                <thead>
                  <tr><th>ID</th><th>Nama kopi</th><th>Kategori</th><th>Stok</th><th>Harga</th><th>Aksi</th></tr>
                </thead>
                <tbody id="products-body">
                  <tr><td colspan="6" class="table-message">Memuat kopi...</td></tr>
                </tbody>
              </table>
            </div>
          </section>
        </section>

        <p id="dashboard-message" class="error-message" role="alert"></p>
      </main>
    </div>
  </div>

  <dialog id="edit-dialog" class="edit-dialog">
    <form id="transaction-form">
      <h2 class="panel-heading">Edit transaksi</h2>
      <input name="id" type="hidden">
      <label>Jumlah kopi
        <input name="quantity" type="number" min="1" step="1" required>
      </label>
      <label>Status
        <select name="status" required>
          <option value="pending">Menunggu</option>
          <option value="completed">Selesai</option>
          <option value="cancelled">Dibatalkan</option>
        </select>
      </label>
      <p id="edit-message" class="error-message" role="alert"></p>
      <div class="form-actions">
        <button class="small-button" type="submit">Simpan perubahan</button>
        <button id="close-dialog" class="small-button secondary-button" type="button">Batal</button>
      </div>
    </form>
  </dialog>

  <dialog id="product-dialog" class="edit-dialog">
    <form id="product-edit-form">
      <h2 class="panel-heading">Edit kopi</h2>
      <input name="id" type="hidden">
      <label>Nama kopi
        <input name="name" type="text" maxlength="150" required>
      </label>
      <label>Kategori
        <input name="category" type="text" maxlength="100">
      </label>
      <label>Stok tersedia
        <input name="stock" type="number" min="0" step="1" required>
      </label>
      <label>Harga
        <input name="price" type="number" min="0" step="0.01" required>
      </label>
      <p id="product-edit-message" class="error-message" role="alert"></p>
      <div class="form-actions">
        <button class="small-button" type="submit">Simpan perubahan</button>
        <button id="close-product-dialog" class="small-button secondary-button" type="button">Batal</button>
      </div>
    </form>
  </dialog>

  <script>
    const csrfToken = <?= $csrfToken ?>;
    const navLinks = document.querySelectorAll("[data-view]");
    const message = document.querySelector("#dashboard-message");
    let products = [];

    async function api(action, options = {}) {
      const response = await fetch(`backend.php?action=${action}`, {
        credentials: "same-origin",
        ...options
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Terjadi kesalahan.");
      return result;
    }

    function formatMoney(value) {
      return new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        maximumFractionDigits: 0
      }).format(Number(value));
    }

    function formatDate(value) {
      return new Date(value.replace(" ", "T")).toLocaleString("id-ID");
    }

    function addCell(row, value) {
      const cell = document.createElement("td");
      cell.textContent = value;
      row.append(cell);
      return cell;
    }

    function renderProducts(products) {
      const body = document.querySelector("#products-body");
      body.replaceChildren();
      const productSelect = document.querySelector("#transaction-product");
      productSelect.replaceChildren(new Option("Pilih kopi", ""));
      products.forEach((product) => {
        productSelect.add(new Option(
          `${product.name} · stok ${product.stock} · ${formatMoney(product.price)}`,
          product.id
        ));
      });

      if (!products.length) {
        body.innerHTML = '<tr><td colspan="6" class="table-message">Belum ada kopi. Gunakan tombol Tambah kopi.</td></tr>';
        return;
      }

      products.forEach((product) => {
        const row = document.createElement("tr");
        addCell(row, product.id);
        addCell(row, product.name);
        addCell(row, product.category || "—");
        addCell(row, product.stock);
        addCell(row, formatMoney(product.price));
        const actions = addCell(row, "");
        const edit = document.createElement("button");
        edit.className = "table-button";
        edit.type = "button";
        edit.textContent = "Edit";
        edit.addEventListener("click", () => openProductEdit(product));

        const remove = document.createElement("button");
        remove.className = "table-button danger-button";
        remove.type = "button";
        remove.textContent = "Hapus";
        remove.addEventListener("click", () => deleteProduct(product));
        actions.append(edit, remove);
        body.append(row);
      });
    }

    function renderTransactions(rows) {
      const body = document.querySelector("#transactions-body");
      body.replaceChildren();

      if (!rows.length) {
        body.innerHTML = '<tr><td colspan="7" class="table-message">Belum ada transaksi.</td></tr>';
      } else {
        rows.forEach((transaction) => {
          const row = document.createElement("tr");
          addCell(row, transaction.id);
          addCell(row, formatDate(transaction.created_at));
          addCell(row, transaction.product_name);
          addCell(row, transaction.quantity);
          addCell(row, formatMoney(transaction.total));

          const statusCell = addCell(row, "");
          const status = document.createElement("span");
          status.className = `status status-${transaction.status}`;
          status.textContent = {
            pending: "Menunggu",
            completed: "Selesai",
            cancelled: "Dibatalkan"
          }[transaction.status] || transaction.status;
          statusCell.append(status);

          const actions = addCell(row, "");
          const edit = document.createElement("button");
          edit.className = "table-button";
          edit.type = "button";
          edit.textContent = "Edit";
          edit.addEventListener("click", () => openEdit(transaction));

          const remove = document.createElement("button");
          remove.className = "table-button danger-button";
          remove.type = "button";
          remove.textContent = "Hapus";
          remove.addEventListener("click", () => deleteTransaction(transaction.id));
          actions.append(edit, remove);
          body.append(row);
        });
      }

      const recent = document.querySelector("#recent-transactions");
      recent.replaceChildren();
      if (!rows.length) {
        recent.className = "empty-state";
        recent.textContent = "Belum ada transaksi.";
        return;
      }

      recent.className = "table-wrap";
      const table = document.createElement("table");
      table.className = "data-table";
      const tableHead = document.createElement("thead");
      const headingRow = document.createElement("tr");
      ["Transaksi", "Tanggal", "Total", "Status"].forEach((heading) => {
        const cell = document.createElement("th");
        cell.textContent = heading;
        headingRow.append(cell);
      });
      tableHead.append(headingRow);

      const tableBody = document.createElement("tbody");
      rows.slice(0, 5).forEach((transaction) => {
        const row = document.createElement("tr");
        addCell(row, `#${transaction.id} · ${transaction.product_name}`);
        addCell(row, formatDate(transaction.created_at));
        addCell(row, formatMoney(transaction.total));
        addCell(row, {
          pending: "Menunggu",
          completed: "Selesai",
          cancelled: "Dibatalkan"
        }[transaction.status] || transaction.status);
        tableBody.append(row);
      });
      table.append(tableHead, tableBody);
      recent.append(table);
    }

    async function loadDashboard() {
      message.textContent = "";
      const results = await Promise.allSettled([
        api("products"),
        api("transactions"),
        api("summary")
      ]);
      const errors = [];

      if (results[0].status === "fulfilled") {
        const productRows = results[0].value.data;
        renderProducts(productRows);
        products = productRows;
        document.querySelector("#stat-products").textContent = productRows.length;
        document.querySelector("#stat-stock").textContent = productRows
          .reduce((sum, product) => sum + Number(product.stock), 0);
      } else {
        document.querySelector("#stat-products").textContent = "—";
        document.querySelector("#stat-stock").textContent = "—";
        errors.push(results[0].reason.message);
      }

      if (results[1].status === "fulfilled") {
        const transactionRows = results[1].value.data;
        renderTransactions(transactionRows);
        document.querySelector("#stat-transactions").textContent = transactionRows.length;
      } else {
        document.querySelector("#stat-transactions").textContent = "—";
        const recent = document.querySelector("#recent-transactions");
        recent.className = "empty-state";
        recent.textContent = "Transaksi tidak dapat dimuat.";
        errors.push(results[1].reason.message);
      }

      if (results[2].status === "fulfilled") {
        document.querySelector("#stat-revenue").textContent =
          formatMoney(results[2].value.data.completed_revenue);
      } else {
        document.querySelector("#stat-revenue").textContent = "Gagal memuat";
        errors.push(results[2].reason.message);
      }

      message.textContent = errors.join(" ");
    }

    function showView(name) {
      document.querySelectorAll(".view").forEach((view) => {
        view.hidden = view.id !== `view-${name}`;
      });
      navLinks.forEach((link) => {
        link.classList.toggle("active", link.dataset.view === name);
      });
      const activeLink = document.querySelector(`.nav-link[data-view="${name}"]`);
      document.querySelector(".topbar-title").textContent = activeLink.textContent;
    }

    navLinks.forEach((link) => {
      link.addEventListener("click", (event) => {
        event.preventDefault();
        const name = link.dataset.view;
        showView(name);
        history.replaceState(null, "", link.getAttribute("href"));
      });
    });

    document.querySelectorAll(".text-link").forEach((link) => {
      link.addEventListener("click", (event) => {
        event.preventDefault();
        showView(link.dataset.view);
        history.replaceState(null, "", link.getAttribute("href"));
      });
    });

    const requestedView = location.hash.slice(1);
    showView(["overview", "transactions", "products"].includes(requestedView)
      ? requestedView
      : "overview");
    loadDashboard();

    const productDialog = document.querySelector("#product-dialog");
    const productEditForm = document.querySelector("#product-edit-form");

    function openProductEdit(product) {
      productEditForm.elements.id.value = product.id;
      productEditForm.elements.name.value = product.name;
      productEditForm.elements.category.value = product.category || "";
      productEditForm.elements.stock.value = product.stock;
      productEditForm.elements.price.value = product.price;
      document.querySelector("#product-edit-message").textContent = "";
      productDialog.showModal();
    }

    document.querySelector("#close-product-dialog").addEventListener("click", () => {
      productDialog.close();
    });

    productEditForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      const button = productEditForm.querySelector('[type="submit"]');
      button.disabled = true;
      const values = new FormData(productEditForm);

      try {
        await api("update_product", {
          method: "POST",
          headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
          body: JSON.stringify({
            id: values.get("id"),
            name: values.get("name"),
            category: values.get("category"),
            stock: values.get("stock"),
            price: values.get("price")
          })
        });
        productDialog.close();
        await loadDashboard();
      } catch (error) {
        document.querySelector("#product-edit-message").textContent = error.message;
      } finally {
        button.disabled = false;
      }
    });

    async function deleteProduct(product) {
      if (!window.confirm(`Hapus kopi "${product.name}"?`)) return;
      message.textContent = "";

      try {
        await api("delete_product", {
          method: "POST",
          headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
          body: JSON.stringify({ id: product.id })
        });
        await loadDashboard();
      } catch (error) {
        message.textContent = error.message;
      }
    }

    const createTransactionForm = document.querySelector("#transaction-create-form");
    const productSelect = document.querySelector("#transaction-product");
    const productInfo = document.querySelector("#transaction-product-info");

    document.querySelector("#show-transaction-form").addEventListener("click", () => {
      if (!products.length) {
        message.textContent = "Tambahkan kopi terlebih dahulu sebelum membuat transaksi.";
        return;
      }
      message.textContent = "";
      createTransactionForm.hidden = false;
      productSelect.focus();
    });

    document.querySelector("#cancel-transaction-form").addEventListener("click", () => {
      createTransactionForm.reset();
      createTransactionForm.hidden = true;
      document.querySelector("#transaction-create-message").textContent = "";
      productInfo.textContent = "";
    });

    productSelect.addEventListener("change", () => {
      const product = products.find((item) => String(item.id) === productSelect.value);
      productInfo.textContent = product
        ? `Stok tersedia: ${product.stock} · Harga satuan: ${formatMoney(product.price)}`
        : "";
    });

    createTransactionForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      const button = createTransactionForm.querySelector('[type="submit"]');
      const error = document.querySelector("#transaction-create-message");
      button.disabled = true;
      error.textContent = "";

      try {
        const values = new FormData(createTransactionForm);
        await api("add_transaction", {
          method: "POST",
          headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
          body: JSON.stringify({
            product_id: values.get("product_id"),
            quantity: values.get("quantity")
          })
        });
        createTransactionForm.reset();
        createTransactionForm.hidden = true;
        productInfo.textContent = "";
        await loadDashboard();
      } catch (error) {
        document.querySelector("#transaction-create-message").textContent = error.message;
      } finally {
        button.disabled = false;
      }
    });

    document.querySelector("#show-product-form").addEventListener("click", () => {
      document.querySelector("#product-form").hidden = false;
      document.querySelector("#product-form [name=name]").focus();
    });

    document.querySelector("#cancel-product-form").addEventListener("click", () => {
      const form = document.querySelector("#product-form");
      form.reset();
      form.hidden = true;
    });

    document.querySelector("#product-form").addEventListener("submit", async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const button = form.querySelector('[type="submit"]');
      button.disabled = true;
      message.textContent = "";

      try {
        const values = new FormData(form);
        await api("add_product", {
          method: "POST",
          headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
          body: JSON.stringify({
            name: values.get("name"),
            category: values.get("category"),
            stock: values.get("stock"),
            price: values.get("price")
          })
        });
        form.reset();
        form.hidden = true;
        await loadDashboard();
      } catch (error) {
        message.textContent = error.message;
      } finally {
        button.disabled = false;
      }
    });

    const dialog = document.querySelector("#edit-dialog");
    const transactionForm = document.querySelector("#transaction-form");

    function openEdit(transaction) {
      transactionForm.elements.id.value = transaction.id;
      transactionForm.elements.quantity.value = transaction.quantity;
      transactionForm.elements.status.value = transaction.status;
      document.querySelector("#edit-message").textContent = "";
      dialog.showModal();
    }

    document.querySelector("#close-dialog").addEventListener("click", () => dialog.close());

    transactionForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      const button = transactionForm.querySelector('[type="submit"]');
      button.disabled = true;
      const values = new FormData(transactionForm);

      try {
        await api("update_transaction", {
          method: "POST",
          headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
          body: JSON.stringify({
            id: values.get("id"),
            quantity: values.get("quantity"),
            status: values.get("status")
          })
        });
        dialog.close();
        await loadDashboard();
      } catch (error) {
        document.querySelector("#edit-message").textContent = error.message;
      } finally {
        button.disabled = false;
      }
    });

    async function deleteTransaction(id) {
      if (!window.confirm(`Hapus transaksi #${id}? Tindakan ini tidak dapat dibatalkan.`)) return;
      message.textContent = "";

      try {
        await api("delete_transaction", {
          method: "POST",
          headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
          body: JSON.stringify({ id })
        });
        await loadDashboard();
      } catch (error) {
        message.textContent = error.message;
      }
    }

    document.querySelector("#logout-button").addEventListener("click", async (event) => {
      const button = event.currentTarget;
      button.disabled = true;

      try {
        await api("logout", {
          method: "POST",
          headers: { "X-CSRF-Token": csrfToken }
        });
        location.replace("index.html");
      } catch (error) {
        message.textContent = error.message;
        button.disabled = false;
      }
    });
  </script>
</body>
</html>
