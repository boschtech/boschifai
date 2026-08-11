---
name: boschifai-gen-e2e-api-rest
description: "E2E test generation rules for REST API journeys across real services with no mocks (Testcontainers, RestAssured, supertest)"
---

# E2E Test Generation — REST API

Tech-specific rules for generating REST API E2E tests that drive HTTP flows through a fully running service stack with no mocked dependencies. Extends the base `boschifai-gen-e2e` skill.

## What Distinguishes API REST E2E from Component Tests

| Aspect | Component Test | API REST E2E |
|--------|---------------|-------------|
| Dependencies | Mocked (`@MockBean`, `nock`) | Real (Testcontainers, deployed) |
| Scope | Single service | Multiple services / full API chain |
| Infrastructure | In-process fakes | Real DB, real downstream services |
| Goal | Verify single service contract | Verify end-to-end API journey |

## Test Frameworks

### Java (Spring Boot)

| Type | Framework | Typical Version |
|------|-----------|-----------------|
| Test runner | JUnit 5 (Jupiter) | 5.10+ |
| HTTP client | TestRestTemplate or RestAssured | Match project |
| Containers | Testcontainers (PostgreSQL, Redis, etc.) | 1.19+ |
| Multi-service | Docker Compose Testcontainers module | 1.19+ |
| Assertions | AssertJ + JSONPath | 3.25+ |
| Coverage | JaCoCo | 0.8+ |

### Node.js / Next.js BFF

| Type | Framework | Typical Version |
|------|-----------|-----------------|
| Test runner | Jest or Vitest | 29+ / 1.x |
| HTTP client | supertest or axios | Match project |
| Containers | Testcontainers for Node | 1.x |
| Assertions | Jest expect | Built-in |

## Test File Convention

- Java: `src/test/java/<package>/e2e/<Journey>E2ETest.java`
- Node: `test/e2e/<journey-name>.e2e.spec.js`
- Test data: `src/test/resources/fixtures/e2e/` or `test/e2e/fixtures/`

## Java E2E Test Structure

```java
@SpringBootTest(webEnvironment = SpringBootTest.WebEnvironment.RANDOM_PORT)
@Testcontainers
@ActiveProfiles("e2e")
class OrderJourneyE2ETest {

    @Container
    static PostgreSQLContainer<?> postgres = new PostgreSQLContainer<>("postgres:15")
        .withDatabaseName("ordersdb");

    @Container
    static GenericContainer<?> paymentService = new GenericContainer<>(
            DockerImageName.parse("internal/payment-service:latest"))
        .withExposedPorts(8081)
        .withEnv("SPRING_PROFILES_ACTIVE", "e2e");

    @DynamicPropertySource
    static void configureProperties(DynamicPropertyRegistry registry) {
        registry.add("spring.datasource.url", postgres::getJdbcUrl);
        registry.add("spring.datasource.username", postgres::getUsername);
        registry.add("spring.datasource.password", postgres::getPassword);
        registry.add("payment.service.url",
            () -> "http://localhost:" + paymentService.getMappedPort(8081));
    }

    @Autowired
    private TestRestTemplate restTemplate;

    @Autowired
    private OrderRepository orderRepository;

    @BeforeEach
    void setUp() {
        orderRepository.deleteAll();
    }

    @Nested
    @DisplayName("Create → Pay → Confirm journey")
    class OrderLifecycle {

        @Test
        @DisplayName("should complete full order lifecycle when all services are available")
        void shouldCompleteOrderLifecycle() {
            // GIVEN — create order
            var createRequest = OrderE2EFixture.validCreateRequest();
            var createResponse = restTemplate.postForEntity(
                "/api/v1/orders", createRequest, OrderResponse.class);

            assertThat(createResponse.getStatusCode()).isEqualTo(HttpStatus.CREATED);
            var orderId = createResponse.getBody().getId();

            // WHEN — trigger payment
            var payRequest = new PaymentRequest(orderId, "CARD-4111");
            var payResponse = restTemplate.postForEntity(
                "/api/v1/orders/{id}/pay", payRequest, PaymentResponse.class, orderId);

            assertThat(payResponse.getStatusCode()).isEqualTo(HttpStatus.OK);

            // THEN — confirm order is in PAID state
            var fetchResponse = restTemplate.getForEntity(
                "/api/v1/orders/{id}", OrderResponse.class, orderId);

            assertThat(fetchResponse.getStatusCode()).isEqualTo(HttpStatus.OK);
            assertThat(fetchResponse.getBody().getStatus()).isEqualTo("PAID");
        }

        @Test
        @DisplayName("should return 402 when payment is declined")
        void shouldReturn402WhenPaymentDeclined() {
            var createRequest = OrderE2EFixture.validCreateRequest();
            var createResponse = restTemplate.postForEntity(
                "/api/v1/orders", createRequest, OrderResponse.class);
            var orderId = createResponse.getBody().getId();

            var payRequest = new PaymentRequest(orderId, "CARD-0000"); // declined card

            var payResponse = restTemplate.postForEntity(
                "/api/v1/orders/{id}/pay", payRequest, ErrorResponse.class, orderId);

            assertThat(payResponse.getStatusCode()).isEqualTo(HttpStatus.PAYMENT_REQUIRED);
            assertThat(payResponse.getBody().getCode()).isEqualTo("PAYMENT_DECLINED");

            // Order must remain in PENDING state
            var fetchResponse = restTemplate.getForEntity(
                "/api/v1/orders/{id}", OrderResponse.class, orderId);
            assertThat(fetchResponse.getBody().getStatus()).isEqualTo("PENDING");
        }

        @Test
        @DisplayName("should be idempotent — paying twice returns 409")
        void shouldRejectDuplicatePayment() {
            var orderId = createAndPayOrder();

            var payRequest = new PaymentRequest(orderId, "CARD-4111");
            var secondPayResponse = restTemplate.postForEntity(
                "/api/v1/orders/{id}/pay", payRequest, ErrorResponse.class, orderId);

            assertThat(secondPayResponse.getStatusCode()).isEqualTo(HttpStatus.CONFLICT);
        }

        private String createAndPayOrder() {
            var createRequest = OrderE2EFixture.validCreateRequest();
            var orderId = restTemplate.postForEntity(
                "/api/v1/orders", createRequest, OrderResponse.class)
                .getBody().getId();
            restTemplate.postForEntity("/api/v1/orders/{id}/pay",
                new PaymentRequest(orderId, "CARD-4111"), PaymentResponse.class, orderId);
            return orderId;
        }
    }
}
```

## Node.js E2E Test Structure

```javascript
const request = require('supertest');
const { GenericContainer } = require('testcontainers');
const app = require('../../src/app');

describe('Order Journey E2E', () => {
  let server;
  let dbContainer;

  beforeAll(async () => {
    dbContainer = await new GenericContainer('postgres:15')
      .withEnvironment({ POSTGRES_DB: 'ordersdb', POSTGRES_PASSWORD: 'test' })
      .withExposedPorts(5432)
      .start();

    process.env.DATABASE_URL =
      `postgres://postgres:test@localhost:${dbContainer.getMappedPort(5432)}/ordersdb`;

    server = app.listen(0);
  });

  afterAll(async () => {
    server.close();
    await dbContainer.stop();
  });

  beforeEach(async () => {
    await request(server).delete('/api/test/data'); // reset seed data
  });

  describe('Create → Pay → Confirm', () => {
    it('should complete order lifecycle', async () => {
      // Create
      const createRes = await request(server)
        .post('/api/v1/orders')
        .set('Authorization', `Bearer ${validToken()}`)
        .send({ customerId: 'CUST-1', items: [{ sku: 'SKU-001', qty: 2 }] })
        .expect(201);

      const orderId = createRes.body.id;

      // Pay
      await request(server)
        .post(`/api/v1/orders/${orderId}/pay`)
        .send({ cardToken: 'CARD-4111' })
        .expect(200);

      // Confirm state
      const fetchRes = await request(server)
        .get(`/api/v1/orders/${orderId}`)
        .expect(200);

      expect(fetchRes.body.status).toBe('PAID');
    });
  });
});
```

## Test Data / Fixtures

### Java fixture factory

```java
public class OrderE2EFixture {

    public static CreateOrderRequest validCreateRequest() {
        return CreateOrderRequest.builder()
            .customerId("CUST-E2E-" + UUID.randomUUID())
            .items(List.of(new OrderItem("SKU-001", 2)))
            .deliveryAddress(Address.builder()
                .line1("1 Test Street")
                .city("Dubai")
                .country("AE")
                .build())
            .build();
    }
}
```

### Fixture JSON files

```
src/test/resources/fixtures/e2e/
├── order/
│   ├── valid-create-request.json
│   ├── payment-declined-request.json
│   └── expected-paid-order.json
```

## application-e2e.yml

```yaml
# src/test/resources/application-e2e.yml
spring:
  jpa:
    hibernate:
      ddl-auto: create-drop
  datasource:
    hikari:
      maximum-pool-size: 5

logging:
  level:
    root: WARN
    com.yourorg: INFO

# External services override by DynamicPropertySource at runtime
```

## Required Scenarios Per REST API Journey

| Category | What to Test | Min Count |
|----------|-------------|-----------|
| Happy path | Full multi-step flow completes successfully | 1 per journey |
| Invalid input | Bad/missing fields rejected at first API call | 1 per required field group |
| Business rule violation | e.g. pay twice, cancel confirmed order | 1 per business invariant |
| Downstream failure | Real downstream returns error; check propagation | 1 per downstream service |
| Idempotency | Repeating an operation returns same or conflict | 1 per mutating endpoint |
| State consistency | DB state matches API response at each step | 1 per state transition |

## CI Integration

```xml
<!-- Maven Failsafe for E2E tests -->
<plugin>
    <groupId>org.apache.maven.plugins</groupId>
    <artifactId>maven-failsafe-plugin</artifactId>
    <configuration>
        <includes>
            <include>**/*E2ETest.java</include>
        </includes>
        <systemPropertyVariables>
            <spring.profiles.active>e2e</spring.profiles.active>
        </systemPropertyVariables>
    </configuration>
</plugin>
```

Run: `mvn verify -Pe2e`

## API REST E2E Generation Checklist

- [ ] File at `src/test/java/<package>/e2e/<Journey>E2ETest.java` or `test/e2e/<journey>.e2e.spec.js`
- [ ] `@Testcontainers` with real DB and any required downstream containers
- [ ] `@ActiveProfiles("e2e")` with `application-e2e.yml`
- [ ] `@DynamicPropertySource` wires container ports into Spring config
- [ ] `@BeforeEach` cleans DB state — no shared state between tests
- [ ] Happy path: multi-step journey succeeds end-to-end
- [ ] Invalid input: bad request rejected at correct step with correct status
- [ ] Business rule: violation returns correct error code and leaves state unchanged
- [ ] Idempotency: duplicate operation returns 409 or idempotent 200
- [ ] State consistency: GET after POST/PUT reflects committed state
- [ ] No `@MockBean` or `nock` — real infrastructure only
- [ ] Fixture factories produce realistic, unique test data
- [ ] Test names: `should <outcome> when <condition>`
