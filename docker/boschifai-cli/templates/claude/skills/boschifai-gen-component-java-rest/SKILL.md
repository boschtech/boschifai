---
name: boschifai-gen-component-java-rest
description: "Component test generation rules for Java Spring Boot REST services (JUnit 5, MockMvc, WireMock, TestContainers)"
---

# Component Test Generation — Java Spring Boot REST

Tech-specific rules for generating component tests in Java Spring Boot REST API services. Extends the base `boschifai-gen-component` skill.

## Test Frameworks

| Type | Framework | Typical Version |
|------|-----------|-----------------|
| Test runner | JUnit 5 (Jupiter) | 5.10+ |
| Spring test | spring-boot-starter-test | Match project |
| HTTP testing | MockMvc or WebTestClient | Built-in |
| HTTP mocking | WireMock | 3.x |
| Assertions | AssertJ | 3.25+ |
| JSON assertions | JsonPath / JSONAssert | Built-in |
| Containers | Testcontainers | 1.19+ |
| Coverage | JaCoCo | 0.8+ |

## Test File Convention

- Location: `src/test/java/<package>/ct/` or `src/test/java/<package>/component/`
- Pattern: `<Feature>ComponentTest.java`
- Integration tests: `<Feature>IT.java` (if using Failsafe plugin)
- Test data: `src/test/resources/fixtures/<service-name>/`

## Test Class Structure

```java
@SpringBootTest(webEnvironment = SpringBootTest.WebEnvironment.RANDOM_PORT)
@AutoConfigureMockMvc
@ActiveProfiles("test")
class OrderComponentTest {

    @Autowired
    private MockMvc mockMvc;

    @Autowired
    private ObjectMapper objectMapper;

    @MockBean
    private PaymentServiceClient paymentClient;

    @MockBean
    private InventoryServiceClient inventoryClient;

    @BeforeEach
    void setUp() {
        // Reset mocks before each test
        reset(paymentClient, inventoryClient);
    }

    @Nested
    @DisplayName("GET /api/v1/orders/{id}")
    class GetOrder {

        @Test
        @DisplayName("should return 200 with order when orderId is valid")
        void shouldReturnOrderSuccessfully() throws Exception {
            // GIVEN
            var orderId = UUID.randomUUID().toString();
            var expectedOrder = OrderFixture.validOrder(orderId);
            when(paymentClient.getPaymentStatus(orderId))
                .thenReturn(PaymentStatus.COMPLETED);

            // WHEN & THEN
            mockMvc.perform(get("/api/v1/orders/{id}", orderId)
                    .header("Authorization", "Bearer " + validToken())
                    .accept(MediaType.APPLICATION_JSON))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.id").value(orderId))
                .andExpect(jsonPath("$.status").value("COMPLETED"))
                .andExpect(jsonPath("$.items").isArray());
        }

        @Test
        @DisplayName("should return 404 when order does not exist")
        void shouldReturn404WhenOrderNotFound() throws Exception {
            var orderId = UUID.randomUUID().toString();
            when(paymentClient.getPaymentStatus(orderId))
                .thenThrow(new OrderNotFoundException(orderId));

            mockMvc.perform(get("/api/v1/orders/{id}", orderId)
                    .header("Authorization", "Bearer " + validToken()))
                .andExpect(status().isNotFound())
                .andExpect(jsonPath("$.error").value("ORDER_NOT_FOUND"));
        }

        @Test
        @DisplayName("should return 401 when no auth token provided")
        void shouldReturn401WithoutAuth() throws Exception {
            mockMvc.perform(get("/api/v1/orders/{id}", UUID.randomUUID()))
                .andExpect(status().isUnauthorized());
        }

        @Test
        @DisplayName("should return 500 when payment service is unavailable")
        void shouldReturn500WhenPaymentServiceFails() throws Exception {
            var orderId = UUID.randomUUID().toString();
            when(paymentClient.getPaymentStatus(orderId))
                .thenThrow(new ServiceUnavailableException("Payment service timeout"));

            mockMvc.perform(get("/api/v1/orders/{id}", orderId)
                    .header("Authorization", "Bearer " + validToken()))
                .andExpect(status().isInternalServerError())
                .andExpect(jsonPath("$.error").value("SERVICE_UNAVAILABLE"));
        }
    }

    @Nested
    @DisplayName("POST /api/v1/orders")
    class CreateOrder {

        @Test
        @DisplayName("should return 201 when order is valid")
        void shouldCreateOrderSuccessfully() throws Exception {
            var request = OrderFixture.validCreateRequest();
            when(inventoryClient.checkAvailability(any()))
                .thenReturn(true);

            mockMvc.perform(post("/api/v1/orders")
                    .header("Authorization", "Bearer " + validToken())
                    .contentType(MediaType.APPLICATION_JSON)
                    .content(objectMapper.writeValueAsString(request)))
                .andExpect(status().isCreated())
                .andExpect(jsonPath("$.id").exists())
                .andExpect(header().exists("Location"));
        }

        @Test
        @DisplayName("should return 400 when required field is missing")
        void shouldReturn400WhenFieldMissing() throws Exception {
            var request = OrderFixture.createRequestMissingCustomerId();

            mockMvc.perform(post("/api/v1/orders")
                    .header("Authorization", "Bearer " + validToken())
                    .contentType(MediaType.APPLICATION_JSON)
                    .content(objectMapper.writeValueAsString(request)))
                .andExpect(status().isBadRequest())
                .andExpect(jsonPath("$.errors[0].field").value("customerId"))
                .andExpect(jsonPath("$.errors[0].message").value("must not be null"));
        }
    }
}
```

## Mocking Patterns

### @MockBean for internal service clients

```java
@MockBean
private ExternalServiceClient externalClient;

// In test:
when(externalClient.fetchData(anyString()))
    .thenReturn(FixtureFactory.validResponse());
```

### WireMock for real HTTP mocking

```java
@WireMockTest(httpPort = 8089)
class ExternalServiceComponentTest {

    @Test
    void shouldHandleExternalServiceTimeout() {
        stubFor(get(urlPathEqualTo("/external/api/data"))
            .willReturn(aResponse()
                .withStatus(200)
                .withFixedDelay(5000)  // simulate timeout
                .withBody("{}")));

        // execute and assert timeout handling
    }
}
```

### Testcontainers for database

```java
@Testcontainers
@SpringBootTest
class OrderRepositoryComponentTest {

    @Container
    static PostgreSQLContainer<?> postgres = new PostgreSQLContainer<>("postgres:15")
        .withDatabaseName("testdb");

    @DynamicPropertySource
    static void configureProperties(DynamicPropertyRegistry registry) {
        registry.add("spring.datasource.url", postgres::getJdbcUrl);
        registry.add("spring.datasource.username", postgres::getUsername);
        registry.add("spring.datasource.password", postgres::getPassword);
    }
}
```

## Test Data / Fixtures

### Fixture factory pattern

```java
public class OrderFixture {

    public static Order validOrder(String orderId) {
        return Order.builder()
            .id(orderId)
            .customerId("CUST-" + UUID.randomUUID())
            .status(OrderStatus.COMPLETED)
            .items(List.of(validItem()))
            .createdAt(Instant.now())
            .build();
    }

    public static CreateOrderRequest validCreateRequest() {
        return CreateOrderRequest.builder()
            .customerId("CUST-123")
            .items(List.of(new OrderItem("SKU-001", 2)))
            .build();
    }

    public static CreateOrderRequest createRequestMissingCustomerId() {
        return CreateOrderRequest.builder()
            .customerId(null)  // deliberately missing
            .items(List.of(new OrderItem("SKU-001", 1)))
            .build();
    }
}
```

### JSON fixture files

```
src/test/resources/fixtures/
├── order/
│   ├── valid-order.json
│   ├── order-not-found.json
│   └── payment-service-error.json
└── payment/
    ├── payment-completed.json
    └── payment-pending.json
```

Load in tests:
```java
String fixture = new String(Files.readAllBytes(
    Path.of("src/test/resources/fixtures/order/valid-order.json")));
```

## Assertion Patterns

```java
// Status + body
.andExpect(status().isOk())
.andExpect(jsonPath("$.id").value(expectedId))
.andExpect(jsonPath("$.items", hasSize(3)))
.andExpect(jsonPath("$.total").value(closeTo(99.99, 0.01)))

// Header assertions
.andExpect(header().string("Content-Type", "application/json"))
.andExpect(header().exists("X-Correlation-Id"))

// Error response shape
.andExpect(jsonPath("$.error").value("VALIDATION_ERROR"))
.andExpect(jsonPath("$.message").isNotEmpty())
.andExpect(jsonPath("$.timestamp").exists())

// Verify mock interactions
verify(paymentClient, times(1)).getPaymentStatus(orderId);
verify(inventoryClient, never()).reserveStock(any());
```

## Test application.yml

```yaml
# src/test/resources/application-test.yml
spring:
  datasource:
    url: jdbc:h2:mem:testdb  # or Testcontainers override
  jpa:
    hibernate:
      ddl-auto: create-drop

external-service:
  payment:
    base-url: http://localhost:${wiremock.server.port}
  inventory:
    base-url: http://localhost:${wiremock.server.port}

logging:
  level:
    root: WARN
    com.emirates: DEBUG
```

## CI Integration

```xml
<!-- Maven Surefire for unit, Failsafe for CT/IT -->
<plugin>
    <groupId>org.apache.maven.plugins</groupId>
    <artifactId>maven-failsafe-plugin</artifactId>
    <configuration>
        <includes>
            <include>**/*ComponentTest.java</include>
            <include>**/*IT.java</include>
        </includes>
    </configuration>
</plugin>
```

Run: `mvn verify -Pct` or `mvn failsafe:integration-test`

## Component Test Generation Checklist

- [ ] File at `src/test/java/<package>/ct/<Feature>ComponentTest.java`
- [ ] `@SpringBootTest(webEnvironment = RANDOM_PORT)` + `@AutoConfigureMockMvc`
- [ ] `@ActiveProfiles("test")`
- [ ] External clients mocked with `@MockBean`
- [ ] Tests grouped in `@Nested` classes per endpoint
- [ ] `@DisplayName` on every `@Nested` and `@Test`
- [ ] Happy path: 200/201 with correct response body
- [ ] Auth failure: 401 without token, 403 with wrong role
- [ ] Not found: 404 for missing resource
- [ ] Validation: 400 with field-level error messages
- [ ] Downstream failure: 500 when dependency unavailable
- [ ] Timeout: verify circuit breaker / fallback behavior
- [ ] Fixtures in `src/test/resources/fixtures/` or Builder/Factory classes
- [ ] `@BeforeEach` resets mocks
- [ ] Mock interactions verified with `verify()`
- [ ] No hardcoded IDs — use `UUID.randomUUID()`
- [ ] Test names describe scenario: `should return <status> when <condition>`
