# Orders import format

The header must contain order_id, date, customer, total, and status.
Order IDs must be unique and nonempty. Dates use YYYY-MM-DD. Customer names
must be nonempty. Totals must be nonnegative decimal numbers.

Accepted statuses are paid, cancelled, and refunded. Row numbers include
the header: the first order is on row 2.

The validator returns JSON containing valid, rows, and errors. Each error
identifies its row, column, and reason.
