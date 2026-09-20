USE practicum4;

-- 1) ДО індексу: очікується type=ALL (повне сканування таблиці),
--    rows ≈ загальна кількість рядків у transactions
EXPLAIN SELECT * FROM transactions WHERE category = 'Продукти';

-- 2) створити індекс на стовпець, за яким фільтруємо (category)
CREATE INDEX idx_transactions_category ON transactions (category);

-- 3) ПІСЛЯ індексу: очікується type=ref (використано індекс),
--    rows ≈ кількість рядків саме з цією категорією (набагато менше)
EXPLAIN SELECT * FROM transactions WHERE category = 'Продукти';